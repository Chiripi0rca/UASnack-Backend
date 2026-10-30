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

// CapacidadService::reservar y liberar (#105): apartar y devolver minutos de cocina.
// La franja que se pasa es la hora de RECOGER: los minutos se apartan en las franjas anteriores
// que no han empezado, primero la más cercana a la recogida.
// SQLite ignora lockForUpdate(), así que aquí se prueba la cuenta, no la concurrencia.
class ReservarFranjaTest extends TestCase
{
    use RefreshDatabase;

    private CapacidadService $capacidad;

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->capacidad = app(CapacidadService::class);

        // fresh(): trae el horario (07:00–19:00) y la duración de franja (10) que pone la base
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ])->fresh();

        // Lunes 26 de octubre de 2026. La tienda cocina 24 min a las 11:00 y 24 a las 11:10.
        foreach (['11:00', '11:10'] as $hora) {
            CapacidadFranja::create([
                'id_tienda' => $this->tienda->id,
                'dia_semana' => Carbon::MONDAY,
                'hora_inicio' => "$hora:00",
                'hora_fin' => Carbon::parse("2026-10-26 $hora:00")->addMinutes(10)->format('H:i:s'),
                'minutos_cocina' => 24,
            ]);
        }

        Carbon::setTestNow($this->hora('10:53'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_aparta_en_la_franja_anterior_a_la_recogida(): void
    {
        $reparto = $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 6);

        $this->assertSame([['franja_inicio' => '2026-10-26 11:10:00', 'minutos' => 6]], $reparto);
        $this->assertSame(6, $this->ocupados('11:10'));
        $this->assertSame(0, $this->ocupados('11:00'));
    }

    public function test_varias_reservas_se_suman(): void
    {
        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 6);
        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 12);

        $this->assertSame(18, $this->ocupados('11:10'));
    }

    // Lo que no cabe en la franja más cercana se toma de la de antes
    public function test_un_pedido_grande_se_reparte_hacia_atras(): void
    {
        $reparto = $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 30);

        $this->assertSame([
            ['franja_inicio' => '2026-10-26 11:10:00', 'minutos' => 24],
            ['franja_inicio' => '2026-10-26 11:00:00', 'minutos' => 6],
        ], $reparto);
    }

    public function test_llenar_justo_el_cupo_si_se_permite(): void
    {
        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 48);

        $this->assertSame([24, 24], [$this->ocupados('11:00'), $this->ocupados('11:10')]);
    }

    public function test_si_no_alcanza_lanza_franja_llena_y_no_aparta_nada(): void
    {
        try {
            $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 49);
            $this->fail('Debió lanzar FRANJA_LLENA');
        } catch (ErrorNegocio $e) {
            $this->assertSame('FRANJA_LLENA', $e->codigo);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame([0, 0], [$this->ocupados('11:00'), $this->ocupados('11:10')]);
    }

    // A las 11:01 la de 11:00 ya empezó: para recoger a las 11:20 solo queda cocinar a las 11:10
    public function test_no_cocina_en_franjas_que_ya_empezaron(): void
    {
        Carbon::setTestNow($this->hora('11:01'));

        $this->expectException(ErrorNegocio::class);

        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 30);
    }

    // A las 11:01, recoger a las 11:10 no se puede: no hay ninguna franja antes sin empezar
    public function test_recoger_en_la_franja_que_viene_no_da_tiempo(): void
    {
        Carbon::setTestNow($this->hora('11:01'));

        $this->expectException(ErrorNegocio::class);

        $this->capacidad->reservar($this->tienda, $this->hora('11:10'), 6);
    }

    public function test_las_franjas_sin_capacidad_no_cocinan(): void
    {
        Carbon::setTestNow($this->hora('10:00'));

        // Para recoger a las 11:00 solo hay franjas de 10:10 a 10:50, y ninguna tiene capacidad
        $this->expectException(ErrorNegocio::class);

        $this->capacidad->reservar($this->tienda, $this->hora('11:00'), 3);
    }

    // #237: lo que no se cocina no aparta nada
    public function test_un_pedido_de_0_minutos_no_aparta_nada(): void
    {
        $this->assertSame([], $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 0));
        $this->assertSame(0, $this->ocupados('11:10'));
    }

    public function test_el_mismo_dia_de_otra_semana_no_comparte_cupo(): void
    {
        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 24);

        $this->assertSame(0, $this->capacidad->minutosOcupados($this->tienda, Carbon::parse('2026-11-02 11:10:00')));
    }

    public function test_liberar_devuelve_exactamente_el_reparto(): void
    {
        $otro = $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 10);
        $reparto = $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 30);

        $this->capacidad->liberar($this->tienda, $reparto);

        // Queda solo el otro pedido
        $this->assertSame([['franja_inicio' => '2026-10-26 11:10:00', 'minutos' => 10]], $otro);
        $this->assertSame([0, 10], [$this->ocupados('11:00'), $this->ocupados('11:10')]);
    }

    public function test_liberar_no_baja_de_cero(): void
    {
        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 6);

        $this->capacidad->liberar($this->tienda, [['franja_inicio' => '2026-10-26 11:10:00', 'minutos' => 50]]);

        $this->assertSame(0, $this->ocupados('11:10'));
    }

    private function hora(string $hora): Carbon
    {
        return Carbon::parse("2026-10-26 $hora:00", CapacidadService::ZONA_HORARIA);
    }

    private function ocupados(string $hora): int
    {
        return $this->capacidad->minutosOcupados($this->tienda, Carbon::parse("2026-10-26 $hora:00"));
    }
}
