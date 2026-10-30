<?php

namespace Tests\Feature\Api\V1;

use App\Models\CapacidadFranja;
use App\Models\Facultades;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// GET y PUT /tienda/capacidad (#106): la capacidad de cocina por franja del encargado.
// Se guarda en minutos de cocina; el dueño la ve y la captura en personas que cocinan.
// Las franjas de estas tiendas duran 10 minutos: cada persona aporta 10 minutos de cocina.
class CapacidadPanelTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargado;

    private Tienda $miTienda;

    private Tienda $otraTienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->encargado = $this->usuario('encargado@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $this->miTienda = $this->tienda($this->encargado);
        $this->otraTienda = $this->tienda($this->usuario('otro@uas.edu.mx', Rol::ENCARGADO_TIENDA));
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

    public function test_lista_solo_las_franjas_de_su_tienda_con_personas(): void
    {
        $this->franja($this->miTienda, 1, '11:10', 10);
        $this->franja($this->miTienda, 1, '11:00', 20);
        $this->franja($this->otraTienda, 1, '11:00', 30);

        $this->comoEncargado()->getJson('/api/v1/tienda/capacidad')
            ->assertOk()
            ->assertExactJson([
                'datos' => [
                    ['dia_semana' => 1, 'hora_inicio' => '11:00', 'hora_fin' => '11:10', 'minutos_cocina' => 20, 'personas' => 2],
                    ['dia_semana' => 1, 'hora_inicio' => '11:10', 'hora_fin' => '11:20', 'minutos_cocina' => 10, 'personas' => 1],
                ],
                'meta' => ['total' => 2, 'duracion_franja_min' => 10],
            ]);
    }

    // Capacidad guardada antes de capturar personas: 15 min en franjas de 10 no da un entero
    public function test_una_capacidad_que_no_cuadra_sale_con_un_decimal(): void
    {
        $this->franja($this->miTienda, 1, '11:00', 15);

        $this->comoEncargado()->getJson('/api/v1/tienda/capacidad')
            ->assertOk()
            ->assertJsonPath('datos.0.minutos_cocina', 15)
            ->assertJsonPath('datos.0.personas', 1.5);
    }

    // --- PUT ---

    public function test_convierte_personas_a_minutos_y_crea_la_franja(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('datos.0.minutos_cocina', 20)
            ->assertJsonPath('datos.0.personas', 2)
            ->assertJsonPath('meta.total', 1);

        // 2 personas × 10 min de franja = 20 minutos de cocina, en la franja que empieza a las 11:00
        $this->assertDatabaseHas('capacidad_franjas', [
            'id_tienda' => $this->miTienda->id,
            'dia_semana' => 1,
            'hora_inicio' => '11:00:00',
            'hora_fin' => '11:10:00',
            'minutos_cocina' => 20,
        ]);
    }

    // Si las franjas duran 15 minutos, cada persona aporta 15
    public function test_usa_la_duracion_de_franja_de_la_tienda(): void
    {
        $this->miTienda->update(['duracion_franja_min' => 15]);

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('datos.0.hora_fin', '11:15')
            ->assertJsonPath('datos.0.minutos_cocina', 30)
            ->assertJsonPath('meta.duracion_franja_min', 15);
    }

    public function test_actualiza_la_franja_que_ya_existe_sin_duplicarla(): void
    {
        $this->franja($this->miTienda, 1, '11:00', 12);

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 3]],
        ])->assertOk();

        $this->assertSame(1, CapacidadFranja::withoutGlobalScopes()->where('id_tienda', $this->miTienda->id)->count());
        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->miTienda->id, 'hora_inicio' => '11:00:00', 'minutos_cocina' => 30]);
    }

    public function test_no_toca_las_otras_franjas_ni_las_de_otra_tienda(): void
    {
        $this->franja($this->miTienda, 1, '11:10', 9);
        $this->franja($this->otraTienda, 1, '11:00', 30);

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 5]],
        ])->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->miTienda->id, 'hora_inicio' => '11:10:00', 'minutos_cocina' => 9]);
        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->otraTienda->id, 'hora_inicio' => '11:00:00', 'minutos_cocina' => 30]);
    }

    public function test_cero_personas_es_valido(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 0]],
        ])->assertOk()->assertJsonPath('datos.0.minutos_cocina', 0);
    }

    public function test_la_tienda_sale_del_encargado_no_del_body(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'id_tienda' => $this->otraTienda->id,
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 5, 'id_tienda' => $this->otraTienda->id]],
        ])->assertOk();

        $this->assertDatabaseHas('capacidad_franjas', ['id_tienda' => $this->miTienda->id, 'hora_inicio' => '11:00:00']);
        $this->assertDatabaseMissing('capacidad_franjas', ['id_tienda' => $this->otraTienda->id]);
    }

    public function test_una_hora_fuera_de_la_rejilla_responde_422_y_no_guarda_nada(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [
                ['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 5],
                ['dia_semana' => 1, 'hora_inicio' => '11:05', 'personas' => 5], // las franjas son de 10 min
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
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '19:00', 'personas' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrorFor('franjas.0.hora_inicio', 'error.campos');
    }

    public function test_valida_los_campos(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.franjas.0', 'Indica las franjas que quieres cambiar.');

        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 9, 'hora_inicio' => 'mediodia', 'personas' => -1]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('franjas.0.dia_semana', 'error.campos')
            ->assertJsonValidationErrorFor('franjas.0.hora_inicio', 'error.campos')
            ->assertJsonValidationErrorFor('franjas.0.personas', 'error.campos');
    }

    // Con personas la capacidad ya no depende del catálogo: una tienda sin productos también la guarda
    public function test_no_depende_de_los_productos_de_la_tienda(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 2]],
        ])->assertOk()->assertJsonPath('datos.0.minutos_cocina', 20);
    }

    public function test_no_acepta_mas_de_20_personas(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 21]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.campos', ['franjas.0.personas' => ['No pueden ser más de 20 personas en una franja.']]);
    }

    // Una capacidad ajustada aquí es la que leen franjasDisponibles, reservar y estimarEspera
    public function test_lo_guardado_queda_en_minutos_de_cocina(): void
    {
        $this->comoEncargado()->putJson('/api/v1/tienda/capacidad', [
            'franjas' => [['dia_semana' => 2, 'hora_inicio' => '08:20', 'personas' => 4]],
        ])->assertOk();

        $franja = CapacidadFranja::withoutGlobalScopes()->where('id_tienda', $this->miTienda->id)->firstOrFail();

        $this->assertSame(40, (int) $franja->minutos_cocina);
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
