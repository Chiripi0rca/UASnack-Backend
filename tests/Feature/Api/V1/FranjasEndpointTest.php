<?php

namespace Tests\Feature\Api\V1;

use App\Models\CapacidadFranja;
use App\Models\Facultades;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CapacidadService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// GET /tiendas/{id}/franjas (#53). Cómo se calculan las franjas lo prueba FranjasDisponiblesTest (#52);
// aquí se prueba lo del endpoint: sesión, validación, 404 y la forma de la respuesta.
class FranjasEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Lunes 26 de octubre de 2026, 10:53 en Culiacán
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:53:00', CapacidadService::ZONA_HORARIA));

        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ])->fresh();

        foreach (['11:00', '11:10', '11:20'] as $hora) {
            CapacidadFranja::create([
                'id_tienda' => $this->tienda->id,
                'dia_semana' => Carbon::MONDAY,
                'hora_inicio' => "$hora:00",
                'hora_fin' => Carbon::parse("2026-10-26 $hora:00")->addMinutes(10)->format('H:i:s'),
                'minutos_cocina' => 24,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas?minutos=6")->assertUnauthorized();
    }

    // Cada franja es una hora para recoger. La de 11:00 no: antes no hay cocina sin empezar.
    public function test_responde_las_franjas_con_la_forma_acordada(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas?minutos=6&limite=2")
            ->assertOk()
            ->assertExactJson([
                'datos' => [
                    ['fecha' => '2026-10-26', 'inicio' => '11:00', 'fin' => '11:10',
                        'minutos_restantes' => 0, 'disponible' => false, 'descuento' => null],
                    ['fecha' => '2026-10-26', 'inicio' => '11:10', 'fin' => '11:20',
                        'minutos_restantes' => 24, 'disponible' => true, 'descuento' => null],
                ],
                // Hoy queda cocina a las 11:00, 11:10 y 11:20: 72 min para recoger desde las 11:30
                'meta' => ['total' => 2, 'cabe_hoy' => true, 'minutos_maximos_hoy' => 72],
            ]);
    }

    public function test_un_pedido_que_no_cabe_hoy_lo_dice_y_dice_cuanto_cabe(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas?minutos=100")
            ->assertOk()
            ->assertJsonPath('meta.cabe_hoy', false)
            ->assertJsonPath('meta.minutos_maximos_hoy', 72)
            ->assertJsonPath('datos.0.disponible', false);
    }

    public function test_sin_limite_regresa_como_maximo_8(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas?minutos=6")
            ->assertOk()
            ->assertJsonCount(8, 'datos');
    }

    public function test_minutos_es_obligatorio(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas")
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.minutos.0', 'Indica los minutos de cocina del pedido.');
    }

    public function test_no_acepta_minutos_negativos_ni_limite_mayor_a_8(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas?minutos=-1&limite=20")
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('minutos', 'error.campos')
            ->assertJsonValidationErrorFor('limite', 'error.campos');
    }

    public function test_cero_minutos_es_valido(): void
    {
        $this->conSesion()
            ->getJson("/api/v1/tiendas/{$this->tienda->id}/franjas?minutos=0&limite=1")
            ->assertOk()
            ->assertJsonPath('datos.0.disponible', true);
    }

    // Inexistente y de baja dan el mismo 404, igual que el catálogo
    public function test_tienda_inexistente_o_de_baja_responde_404(): void
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
            ->getJson("/api/v1/tiendas/{$deBaja->id}/franjas?minutos=6")
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    public function test_tienda_que_no_existe_responde_404(): void
    {
        $this->conSesion()
            ->getJson('/api/v1/tiendas/999/franjas?minutos=6')
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    private function conSesion(): static
    {
        $alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        return $this->withToken($alumno->createToken('cliente')->plainTextToken);
    }
}
