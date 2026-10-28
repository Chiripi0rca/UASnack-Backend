<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoPedido;
use App\Models\CapacidadFranja;
use App\Models\Facultades;
use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CapacidadService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// GET /tiendas/{id}/espera (#57). Cómo se calcula el rango lo prueba EstimarEsperaTest (#56);
// aquí se prueba lo del endpoint: sesión, validación, 404 y la forma de la respuesta.
class EsperaEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tienda $tienda;

    private Usuario $alumno;

    private int $folio = 5700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Lunes 26 de octubre de 2026, 11:05 en Culiacán: dentro de la franja 11:00 a 11:10
        Carbon::setTestNow(Carbon::parse('2026-10-26 11:05:00', CapacidadService::ZONA_HORARIA));

        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'duracion_franja_min' => 10,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        // 10 min de cocina cada 10 min reales = capacidad 1.0
        CapacidadFranja::create([
            'id_tienda' => $this->tienda->id,
            'dia_semana' => Carbon::MONDAY,
            'hora_inicio' => '11:00:00',
            'hora_fin' => '11:10:00',
            'minutos_cocina' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson("/api/v1/tiendas/{$this->tienda->id}/espera?minutos=6")->assertUnauthorized();
    }

    public function test_con_la_cocina_vacia_responde_la_forma_acordada(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/espera?minutos=6")
            ->assertOk()
            ->assertExactJson([
                'datos' => [
                    'min' => 6,
                    'max' => 8,
                    'saturada' => false,
                    'pedidos_en_cocina' => 0,
                    'voy_saliendo_disponible' => true,
                ],
            ]);
    }

    public function test_con_pedidos_en_cocina_el_rango_crece_y_se_satura(): void
    {
        $this->pedido(EstadoPedido::Aceptado, 6);
        $this->pedido(EstadoPedido::EnPreparacion, 8);

        // 14 / 1.0 + 6 = 20  →  20 a 28, y 28 pasa del umbral de 15
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/espera?minutos=6")
            ->assertOk()
            ->assertExactJson([
                'datos' => [
                    'min' => 20,
                    'max' => 28,
                    'saturada' => true,
                    'pedidos_en_cocina' => 2,
                    'voy_saliendo_disponible' => true,
                ],
            ]);
    }

    public function test_minutos_es_obligatorio(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/espera")
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.minutos.0', 'Indica los minutos de cocina del pedido.');
    }

    public function test_no_acepta_minutos_negativos(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/espera?minutos=-1")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('minutos', 'error.campos');
    }

    public function test_cero_minutos_es_valido_y_sigue_siendo_rango(): void
    {
        $respuesta = $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/espera?minutos=0")
            ->assertOk();

        $this->assertGreaterThan($respuesta->json('datos.min'), $respuesta->json('datos.max'));
    }

    // Inexistente y de baja dan el mismo 404, igual que el catálogo y las franjas
    public function test_tienda_de_baja_responde_404(): void
    {
        $deBaja = Tienda::create([
            'nombre' => 'Cerrada',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
        $deBaja->activa = false;
        $deBaja->save();

        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$deBaja->id}/espera?minutos=6")
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    public function test_tienda_que_no_existe_responde_404(): void
    {
        $this->conSesion()
            ->getJson('/api/v1/tiendas/999/espera?minutos=6')
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    private function conSesion(): static
    {
        return $this->withToken($this->alumno->createToken('cliente')->plainTextToken);
    }

    private function pedido(EstadoPedido $estado, int $minutos): Pedido
    {
        return Pedido::create([
            'codigo_pedido' => 'TW-' . ++$this->folio,
            'clave_idempotencia' => (string) Str::uuid(),
            'id_usuario' => $this->alumno->id,
            'id_tienda' => $this->tienda->id,
            'estado' => $estado,
            'modo_retiro' => Pedido::RETIRO_FRANJA,
            'total' => 45,
            'minutos_cocina' => $minutos,
            'pin_retiro' => '0420',
        ]);
    }
}
