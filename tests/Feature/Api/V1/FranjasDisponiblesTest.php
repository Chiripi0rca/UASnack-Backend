<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoDescuento;
use App\Models\CapacidadFranja;
use App\Models\Descuento;
use App\Models\Facultades;
use App\Models\Tienda;
use App\Services\CapacidadService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// CapacidadService::franjasDisponibles (#52): a qué horas puede pasar el alumno por su pedido.
// La franja es la hora de RECOGER; el pedido se cocina en las franjas anteriores que no han empezado.
class FranjasDisponiblesTest extends TestCase
{
    use RefreshDatabase;

    private CapacidadService $capacidad;

    private Tienda $tienda;

    // Lunes 26 de octubre de 2026, 10:53 en Culiacán
    private Carbon $ahora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->capacidad = app(CapacidadService::class);

        // Abre de 07:00 a 19:00 con franjas de 10 minutos (los valores por defecto de la tabla).
        // fresh(): create() no trae los valores por defecto que pone la base de datos.
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ])->fresh();

        $this->ahora = $this->hora('10:53');
        // reservar() decide qué franjas ya empezaron con la hora actual
        Carbon::setTestNow($this->ahora);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_para_recoger_hace_falta_cocina_en_las_franjas_de_antes(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6);

        $this->assertCount(8, $franjas);
        // 11:00 no: antes solo está la de 10:50, que ya empezó. 11:10 sí: se cocina a las 11:00.
        $this->assertSame([
            'fecha' => '2026-10-26',
            'inicio' => '11:00',
            'fin' => '11:10',
            'minutos_restantes' => 0,
            'disponible' => false,
            'descuento' => null,
        ], $franjas[0]);
        $this->assertSame(['11:10', 24, true], [$franjas[1]['inicio'], $franjas[1]['minutos_restantes'], $franjas[1]['disponible']]);
        // Para recoger a las 11:20 se puede cocinar a las 11:00 y a las 11:10
        $this->assertSame(48, $franjas[2]['minutos_restantes']);
    }

    public function test_respeta_el_limite(): void
    {
        $this->capacidadDeTodoElDia(24);

        $this->assertCount(3, $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 3));
    }

    // A las 11:09 la de 11:10 no da tiempo: la primera hora para recoger es 11:20
    public function test_a_las_11_09_la_primera_hora_para_recoger_es_11_20(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->hora('11:09'), 6, 2);

        $this->assertSame(['11:10', '11:20'], $franjas->pluck('inicio')->all());
        $this->assertSame([false, true], $franjas->pluck('disponible')->all());
    }

    public function test_la_primera_franja_del_dia_no_se_ofrece_para_algo_que_se_cocina(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->hora('06:30'), 6, 2);

        $this->assertSame(['07:00', '07:10'], $franjas->pluck('inicio')->all());
        $this->assertSame([false, true], $franjas->pluck('disponible')->all());
    }

    // #237: lo que no se cocina no necesita cocina antes: se recoge en cualquier franja
    public function test_un_pedido_que_no_se_cocina_se_recoge_en_cualquier_franja(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 0, 2);

        $this->assertSame([true, true], $franjas->pluck('disponible')->all());
    }

    // Un pedido más grande que una franja se reparte en varias: encuentra lugar más tarde
    public function test_un_pedido_grande_encuentra_lugar_mas_tarde(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 30, 3);

        // 11:00 → 0 antes, 11:10 → 24 antes, 11:20 → 48 antes
        $this->assertSame([false, false, true], $franjas->pluck('disponible')->all());
    }

    public function test_lo_ya_apartado_resta_a_las_horas_que_dependen_de_esa_cocina(): void
    {
        $this->capacidadDeTodoElDia(24);
        // Un pedido de 20 min para las 11:20: se cocina a las 11:10 (la más cercana a la recogida)
        $this->capacidad->reservar($this->tienda, $this->hora('11:20'), 20);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 3);

        // 11:10 depende solo de las 11:00 (intacta); 11:20 tiene 24 + 4
        $this->assertSame([0, 24, 28], $franjas->pluck('minutos_restantes')->all());
    }

    public function test_no_pasa_del_cierre(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->hora('18:35'), 6);

        // 18:40–18:50 y 18:50–19:00. Ninguna termina después de las 19:00.
        $this->assertSame(['18:40', '18:50'], $franjas->pluck('inicio')->all());
        $this->assertSame('19:00', $franjas->last()['fin']);
    }

    public function test_despues_del_cierre_no_hay_franjas(): void
    {
        $this->capacidadDeTodoElDia(24);

        $this->assertCount(0, $this->capacidad->franjasDisponibles($this->tienda, $this->hora('19:30'), 6));
    }

    public function test_usa_la_duracion_de_franja_de_la_tienda(): void
    {
        $this->tienda->update(['duracion_franja_min' => 15]);
        $this->capacidadDeTodoElDia(24, 15);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 3);

        $this->assertSame(['11:00', '11:15', '11:30'], $franjas->pluck('inicio')->all());
        $this->assertSame('11:15', $franjas->first()['fin']);
    }

    // Igual que reservar() (#105): una franja sin renglón de capacidad no cocina
    public function test_una_franja_sin_capacidad_no_aporta_cocina(): void
    {
        $this->capacidadEn('11:10', 24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 3);

        $this->assertSame(['11:00', '11:10', '11:20'], $franjas->pluck('inicio')->all());
        $this->assertSame([false, false, true], $franjas->pluck('disponible')->all());
    }

    // Si en las primeras 8 no cabe pero más tarde sí, la lista empieza donde cabe
    public function test_si_las_primeras_no_sirven_empieza_en_la_primera_que_si(): void
    {
        $this->capacidadEn('13:00', 24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 2);

        $this->assertSame(['13:10', '13:20'], $franjas->pluck('inicio')->all());
        $this->assertTrue($franjas->first()['disponible']);
    }

    public function test_un_dia_sin_capacidad_no_opera(): void
    {
        // Solo hay capacidad el lunes; el martes no
        $this->capacidadDeTodoElDia(24);

        $this->assertCount(0, $this->capacidad->franjasDisponibles($this->tienda, $this->hora('10:53', '2026-10-27'), 6));
    }

    // La app corre en UTC: las 17:53 UTC son las 10:53 en Culiacán.
    public function test_las_horas_son_las_de_la_tienda(): void
    {
        $this->capacidadDeTodoElDia(24);
        $enUtc = Carbon::parse('2026-10-26 17:53:00', 'UTC');

        $this->assertSame('11:00', $this->capacidad->franjasDisponibles($this->tienda, $enUtc, 6)->first()['inicio']);
    }

    public function test_minutos_maximos_hoy_es_la_cocina_que_queda_antes_de_la_ultima_franja(): void
    {
        $this->capacidadDeTodoElDia(24);

        // A las 18:35 solo quedan 18:40 y 18:50: para recoger a las 18:50 se cocina a las 18:40
        $this->assertSame(24, $this->capacidad->minutosMaximosHoy($this->tienda, $this->hora('18:35')));
        $this->assertSame(0, $this->capacidad->minutosMaximosHoy($this->tienda, $this->hora('19:30')));
    }

    public function test_marca_el_descuento_vigente_de_la_franja(): void
    {
        $this->capacidadDeTodoElDia(24);
        $this->descuento(10, '11:10', '11:30');

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 4);

        $this->assertSame([null, 10, 10, null], $franjas->pluck('descuento')->all());
    }

    // Nunca se acumulan: se marca el mayor (§13.5)
    public function test_con_dos_descuentos_se_marca_el_mayor(): void
    {
        $this->capacidadDeTodoElDia(24);
        $this->descuento(10, '11:00', '12:00');
        $this->descuento(25, '11:00', '11:10');

        $this->assertSame(25, $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6)->first()['descuento']);
    }

    public function test_no_marca_descuentos_inactivos_vencidos_o_de_otro_dia(): void
    {
        $this->capacidadDeTodoElDia(24);
        $this->descuento(10, '11:00', '12:00', ['activo' => EstadoDescuento::Inactivo->value]);
        $this->descuento(15, '11:00', '12:00', ['vigente_hasta' => '2026-10-25']);
        $this->descuento(20, '11:00', '12:00', ['dias_semana' => json_encode([2, 3])]);
        $this->descuento(30, '11:00', '12:00', ['vigente_desde' => '2026-11-01']);

        $this->assertNull($this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6)->first()['descuento']);
    }

    // --- Ayudas ---

    private function hora(string $hora, string $dia = '2026-10-26'): Carbon
    {
        return Carbon::parse("$dia $hora:00", CapacidadService::ZONA_HORARIA);
    }

    // Un renglón de capacidad por franja del lunes, de 07:00 a 19:00
    private function capacidadDeTodoElDia(int $minutosCocina, int $duracion = 10): void
    {
        for ($hora = Carbon::parse('2026-10-26 07:00:00'); $hora->format('H:i') < '19:00'; $hora->addMinutes($duracion)) {
            $this->capacidadEn($hora->format('H:i'), $minutosCocina, $duracion);
        }
    }

    private function capacidadEn(string $hora, int $minutosCocina, int $duracion = 10): void
    {
        $inicio = Carbon::parse("2026-10-26 $hora:00");

        CapacidadFranja::create([
            'id_tienda' => $this->tienda->id,
            'dia_semana' => $inicio->dayOfWeek,
            'hora_inicio' => $inicio->format('H:i:s'),
            'hora_fin' => $inicio->copy()->addMinutes($duracion)->format('H:i:s'),
            'minutos_cocina' => $minutosCocina,
        ]);
    }

    private function descuento(int $porcentaje, string $desde, string $hasta, array $extra = []): void
    {
        Descuento::create([
            'id_tienda' => $this->tienda->id,
            'porcentaje' => $porcentaje,
            'hora_inicio' => "$desde:00",
            'hora_fin' => "$hasta:00",
            'dias_semana' => json_encode([1, 2, 3, 4, 5]),
            'vigente_desde' => '2026-10-01',
            'vigente_hasta' => null,
            'activo' => EstadoDescuento::Activo->value,
            ...$extra,
        ]);
    }
}
