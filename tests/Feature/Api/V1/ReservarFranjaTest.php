<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\Facultades;
use App\Models\Tienda;
use App\Services\CapacidadService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// CapacidadService::reservar y liberar (#105): apartar y devolver minutos de cocina de una franja.
// SQLite ignora lockForUpdate(), así que aquí se prueba la cuenta, no la concurrencia.
class ReservarFranjaTest extends TestCase
{
    use RefreshDatabase;

    private CapacidadService $capacidad;

    private Tienda $tienda;

    // Lunes 26 de octubre de 2026 a las 11:00. En esa franja la tienda cocina 24 min.
    private Carbon $franja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->capacidad = app(CapacidadService::class);

        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        $this->franja = Carbon::parse('2026-10-26 11:00:00');
        CapacidadFranja::create([
            'id_tienda' => $this->tienda->id,
            'dia_semana' => $this->franja->dayOfWeek,
            'hora_inicio' => '11:00:00',
            'hora_fin' => '11:10:00',
            'minutos_cocina' => 24,
        ]);
    }

    public function test_reservar_suma_los_minutos_de_la_franja(): void
    {
        $this->capacidad->reservar($this->tienda, $this->franja, 6);
        $this->capacidad->reservar($this->tienda, $this->franja, 12);

        $this->assertSame(18, $this->capacidad->minutosOcupados($this->tienda, $this->franja));
    }

    public function test_llenar_justo_el_cupo_si_se_permite(): void
    {
        $this->capacidad->reservar($this->tienda, $this->franja, 24);

        $this->assertSame(24, $this->capacidad->minutosOcupados($this->tienda, $this->franja));
    }

    public function test_si_no_alcanza_lanza_franja_llena_y_no_aparta_nada(): void
    {
        $this->capacidad->reservar($this->tienda, $this->franja, 20);

        try {
            $this->capacidad->reservar($this->tienda, $this->franja, 5);
            $this->fail('Debió lanzar FRANJA_LLENA');
        } catch (ErrorNegocio $e) {
            $this->assertSame('FRANJA_LLENA', $e->codigo);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(20, $this->capacidad->minutosOcupados($this->tienda, $this->franja));
    }

    public function test_una_franja_sin_capacidad_no_acepta_reservas(): void
    {
        $this->expectException(ErrorNegocio::class);

        $this->capacidad->reservar($this->tienda, $this->franja->copy()->setTime(15, 0), 3);
    }

    public function test_el_mismo_dia_de_otra_semana_no_comparte_cupo(): void
    {
        $this->capacidad->reservar($this->tienda, $this->franja, 24);

        $this->assertSame(0, $this->capacidad->minutosOcupados($this->tienda, $this->franja->copy()->addWeek()));
    }

    public function test_liberar_devuelve_los_minutos_sin_bajar_de_cero(): void
    {
        $this->capacidad->reservar($this->tienda, $this->franja, 18);

        $this->capacidad->liberar($this->tienda, $this->franja, 6);
        $this->assertSame(12, $this->capacidad->minutosOcupados($this->tienda, $this->franja));

        $this->capacidad->liberar($this->tienda, $this->franja, 50);
        $this->assertSame(0, $this->capacidad->minutosOcupados($this->tienda, $this->franja));
    }
}
