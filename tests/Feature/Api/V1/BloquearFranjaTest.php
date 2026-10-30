<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\Facultades;
use App\Models\Tienda;
use App\Services\CapacidadService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// CapacidadService::bloquearFranja (#171): la franja se lee con lockForUpdate() para que dos pedidos
// al mismo tiempo no tomen el último cupo.
// SQLite no tiene bloqueo de filas, así que aquí se comprueba que la consulta SÍ lo pide (con la
// gramática de PostgreSQL) y que el resultado de dos reservas del último cupo es el correcto.
// La prueba con dos conexiones reales contra PostgreSQL se hace a mano (ver el PR).
class BloquearFranjaTest extends TestCase
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

    public function test_regresa_la_fila_de_capacidad_de_esa_franja(): void
    {
        $fila = DB::transaction(fn () => $this->capacidad->bloquearFranja($this->tienda, $this->franja));

        $this->assertInstanceOf(CapacidadFranja::class, $fila);
        $this->assertSame(24, (int) $fila->minutos_cocina);
    }

    public function test_una_franja_sin_renglon_regresa_null(): void
    {
        $fila = DB::transaction(
            fn () => $this->capacidad->bloquearFranja($this->tienda, $this->franja->copy()->setTime(15, 0))
        );

        $this->assertNull($fila);
    }

    public function test_la_consulta_pide_for_update_en_postgresql(): void
    {
        $conexion = DB::connection();
        $conexion->setQueryGrammar(new PostgresGrammar($conexion));

        try {
            // pretend() solo registra el SQL, no lo ejecuta
            $consultas = $conexion->pretend(fn () => $this->capacidad->bloquearFranja($this->tienda, $this->franja));
        } finally {
            $conexion->useDefaultQueryGrammar();
        }

        $this->assertCount(1, $consultas);
        $this->assertStringContainsString('for update', strtolower($consultas[0]['query']));
    }

    public function test_dos_reservas_del_ultimo_cupo_solo_una_se_queda(): void
    {
        $this->capacidad->reservar($this->tienda, $this->franja, 24);

        try {
            $this->capacidad->reservar($this->tienda, $this->franja, 24);
            $this->fail('La segunda reserva debió lanzar FRANJA_LLENA');
        } catch (ErrorNegocio $e) {
            $this->assertSame('FRANJA_LLENA', $e->codigo);
            $this->assertSame(409, $e->status);
        }

        // Nunca más de lo que cabe
        $this->assertSame(24, $this->capacidad->minutosOcupados($this->tienda, $this->franja));
    }
}
