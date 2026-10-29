<?php

namespace Tests\Feature\Api\V1;

use App\Models\CapacidadFranja;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// GET y PUT /tienda/capacidad (#106): la capacidad de cocina por franja del encargado.
// Se guarda en minutos de cocina; el dueño la ve y la captura en piezas.
// En estas pruebas una pieza vale 3 minutos: los productos de la tienda tardan 2 y 4 (promedio 3).
class CapacidadPanelTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargado;

    private Tienda $miTienda;

    private Tienda $otraTienda;

    private Categorias $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->categoria = Categorias::create(['nombre' => 'Bebidas']);

        $this->encargado = $this->usuario('encargado@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $this->miTienda = $this->tienda($this->encargado);
        $this->otraTienda = $this->tienda($this->usuario('otro@uas.edu.mx', Rol::ENCARGADO_TIENDA));

        // Promedio de 3 min. El de tiempo 0 (#237, no se cocina) no cuenta.
        $this->producto($this->miTienda, 'Café', 2);
        $this->producto($this->miTienda, 'Torta', 4);
        $this->producto($this->miTienda, 'Papitas', 0);
    }

    // --- GET ---

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/v1/tienda/capacidad')->assertUnauthorized();
    }

    public function test_un_alumno_no_entra_responde_403(): void
    {
        $alumno = $this->usuario('alumno@uas.edu.mx', Rol::USUARIO);

        $this->withToken($alumno->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/tienda/capacidad')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_PERMISO');
    }

    public function test_lista_solo_las_franjas_de_su_tienda_con_piezas(): void
    {
        $this->franja($this->miTienda, 1, '11:10', 9);
        $this->franja($this->miTienda, 1, '11:00', 12);
        $this->franja($this->otraTienda, 1, '11:00', 30);

        $this->comoEncargado()->getJson('/api/v1/tienda/capacidad')
            ->assertOk()
            ->assertExactJson([
                'datos' => [
                    ['dia_semana' => 1, 'hora_inicio' => '11:00', 'hora_fin' => '11:10', 'minutos_cocina' => 12, 'piezas' => 4],
                    ['dia_semana' => 1, 'hora_inicio' => '11:10', 'hora_fin' => '11:20', 'minutos_cocina' => 9, 'piezas' => 3],
                ],
                'meta' => ['total' => 2, 'minutos_por_pieza' => 3.0],
            ]);
    }

    public function test_sin_productos_que_se_cocinen_las_piezas_salen_null(): void
    {
        Producto::withoutGlobalScopes()->where('id_tienda', $this->miTienda->id)->delete();
        $this->franja($this->miTienda, 1, '11:00', 12);

        $this->comoEncargado()->getJson('/api/v1/tienda/capacidad')
            ->assertOk()
            ->assertJsonPath('datos.0.minutos_cocina', 12)
            ->assertJsonPath('datos.0.piezas', null)
            ->assertJsonPath('meta.minutos_por_pieza', null);
    }

    // --- PUT ---

    public function test_convierte_piezas_a_minutos_y_crea_la_franja(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 5]],
        ])
            ->assertOk()
            ->assertJsonPath('datos.0.minutos_cocina', 15)
            ->assertJsonPath('datos.0.piezas', 5)
            ->assertJsonPath('meta.total', 1);

        // 5 piezas × 3 min = 15 minutos de cocina, en la franja de 10 minutos que empieza a las 11:00
        $this->assertDatabaseHas('capacidad_franjas', [
            'id_tienda' => $this->miTienda->id,
            'dia_semana' => 1,
            'hora_inicio' => '11:00:00',
            'hora_fin' => '11:10:00',
            'minutos_cocina' => 15,
        ]);
    }

    public function test_actualiza_la_franja_que_ya_existe_sin_duplicarla(): void
    {
        $this->franja($this->miTienda, 1, '11:00', 12);

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 2]],
        ])->assertOk();

        $this->assertSame(1, CapacidadFranja::withoutGlobalScopes()->where('id_tienda', $this->miTienda->id)->count());
        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->miTienda->id, 'hora_inicio' => '11:00:00', 'minutos_cocina' => 6]);
    }

    public function test_no_toca_las_otras_franjas_ni_las_de_otra_tienda(): void
    {
        $this->franja($this->miTienda, 1, '11:10', 9);
        $this->franja($this->otraTienda, 1, '11:00', 30);

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 5]],
        ])->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->miTienda->id, 'hora_inicio' => '11:10:00', 'minutos_cocina' => 9]);
        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->otraTienda->id, 'hora_inicio' => '11:00:00', 'minutos_cocina' => 30]);
    }

    public function test_cero_piezas_es_valido(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 0]],
        ])->assertOk()->assertJsonPath('datos.0.minutos_cocina', 0);
    }

    public function test_la_tienda_sale_del_encargado_no_del_body(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'id_tienda' => $this->otraTienda->id,
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 5, 'id_tienda' => $this->otraTienda->id]],
        ])->assertOk();

        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->miTienda->id, 'hora_inicio' => '11:00:00']);
        $this->assertDatabaseMissing('capacidad_franjas', ['id_tienda' => $this->otraTienda->id]);
    }

    public function test_una_hora_fuera_de_la_rejilla_responde_422_y_no_guarda_nada(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [
                ['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 5],
                ['dia_semana' => 1, 'hora_inicio' => '11:05', 'piezas' => 5], // las franjas son de 10 min
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('franjas.1.hora_inicio', 'error.campos');

        $this->assertDatabaseCount('capacidad_franjas', 0);
    }

    public function test_una_franja_fuera_del_horario_de_la_tienda_responde_422(): void
    {
        // La tienda abre a las 07:00 y cierra a las 19:00: la franja de las 19:00 ya no cabe
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '19:00', 'piezas' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrorFor('franjas.0.hora_inicio', 'error.campos');
    }

    public function test_valida_los_campos(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.franjas.0', 'Indica las franjas que quieres cambiar.');

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 9, 'hora_inicio' => 'mediodia', 'piezas' => -1]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('franjas.0.dia_semana', 'error.campos')
            ->assertJsonValidationErrorFor('franjas.0.hora_inicio', 'error.campos')
            ->assertJsonValidationErrorFor('franjas.0.piezas', 'error.campos');
    }

    public function test_sin_productos_que_se_cocinen_no_se_puede_guardar(): void
    {
        Producto::withoutGlobalScopes()->where('id_tienda', $this->miTienda->id)->delete();

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 5]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'SIN_PRODUCTOS_QUE_SE_COCINEN');
    }

    // Una capacidad ajustada aquí es la que leen franjasDisponibles, reservar y estimarEspera
    public function test_lo_guardado_queda_en_minutos_de_cocina(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 2, 'hora_inicio' => '08:20', 'piezas' => 4]],
        ])->assertOk();

        $franja = CapacidadFranja::withoutGlobalScopes()->where('id_tienda', $this->miTienda->id)->firstOrFail();

        $this->assertSame(12, (int) $franja->minutos_cocina);
    }

    private function comoEncargado(): static
    {
        return $this->withToken($this->encargado->createToken('cliente')->plainTextToken);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $usuario->asignarRol($rol);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }

    private function tienda(Usuario $encargado): Tienda
    {
        return Tienda::create([
            'nombre' => "Tienda de {$encargado->correo_institucional}",
            'id_facultad' => $encargado->id_facultad,
            'id_dueno' => $encargado->id,
            'duracion_franja_min' => 10,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    private function producto(Tienda $tienda, string $nombre, int $tiempoMin): Producto
    {
        return Producto::withoutGlobalScopes()->create([
            'id_tienda' => $tienda->id,
            'id_categoria' => $this->categoria->id,
            'nombre' => $nombre,
            'precio' => 30,
            'stock_hoy' => 10,
            'tiempo_prep_min' => $tiempoMin,
            'tiempo_prep_max' => $tiempoMin + 2,
        ]);
    }

    private function franja(Tienda $tienda, int $dia, string $hora, int $minutosCocina): void
    {
        CapacidadFranja::withoutGlobalScopes()->create([
            'id_tienda' => $tienda->id,
            'dia_semana' => $dia,
            'hora_inicio' => "$hora:00",
            'hora_fin' => date('H:i:s', strtotime("$hora:00 +10 minutes")),
            'minutos_cocina' => $minutosCocina,
        ]);
    }
}
