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

// CapacidadService::franjasDisponibles (#52): la rejilla de franjas que ve el alumno al elegir retiro.
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

        $this->ahora = Carbon::parse('2026-10-26 10:53:00', CapacidadService::ZONA_HORARIA);
    }

    public function test_regresa_las_proximas_franjas_a_partir_de_ahora(): void
    {
        $this->capacidadDeTodoElDia(24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6);

        $this->assertCount(8, $franjas);
        $this->assertSame(
            ['11:00', '11:10', '11:20', '11:30', '11:40', '11:50', '12:00', '12:10'],
            $franjas->pluck('inicio')->all()
        );
        $this->assertSame([
            'fecha' => '2026-10-26',
            'inicio' => '11:00',
            'fin' => '11:10',
            'minutos_restantes' => 24,
            'disponible' => true,
            'descuento' => null,
        ], $franjas->first());
    }

    public function test_respeta_el_limite(): void
    {
        $this->capacidadDeTodoElDia(24);

        $this->assertCount(3, $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 3));
    }

    public function test_la_franja_que_ya_empezo_no_se_ofrece(): void
    {
        $this->capacidadDeTodoElDia(24);
        $justoALas11 = Carbon::parse('2026-10-26 11:00:00', CapacidadService::ZONA_HORARIA);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $justoALas11, 6);

        $this->assertSame('11:10', $franjas->first()['inicio']);
    }

    public function test_no_pasa_del_cierre(): void
    {
        $this->capacidadDeTodoElDia(24);
        $tarde = Carbon::parse('2026-10-26 18:35:00', CapacidadService::ZONA_HORARIA);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $tarde, 6);

        // 18:40–18:50 y 18:50–19:00. Ninguna termina después de las 19:00.
        $this->assertSame(['18:40', '18:50'], $franjas->pluck('inicio')->all());
        $this->assertSame('19:00', $franjas->last()['fin']);
    }

    public function test_despues_del_cierre_no_hay_franjas(): void
    {
        $this->capacidadDeTodoElDia(24);
        $noche = Carbon::parse('2026-10-26 19:30:00', CapacidadService::ZONA_HORARIA);

        $this->assertCount(0, $this->capacidad->franjasDisponibles($this->tienda, $noche, 6));
    }

    public function test_usa_la_duracion_de_franja_de_la_tienda(): void
    {
        $this->tienda->update(['duracion_franja_min' => 15]);
        $this->capacidadDeTodoElDia(24, 15);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 3);

        $this->assertSame(['11:00', '11:15', '11:30'], $franjas->pluck('inicio')->all());
        $this->assertSame('11:15', $franjas->first()['fin']);
    }

    public function test_marca_llena_la_franja_donde_no_cabe_el_pedido(): void
    {
        $this->capacidadDeTodoElDia(24);
        $this->capacidad->reservar($this->tienda, $this->franja('11:00'), 20);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6);

        // Quedan 4 minutos: no caben los 6 del pedido. La siguiente sigue libre.
        $this->assertSame(4, $franjas[0]['minutos_restantes']);
        $this->assertFalse($franjas[0]['disponible']);
        $this->assertTrue($franjas[1]['disponible']);
    }

    // Una franja llena para un pedido grande puede servir para uno chico: "llena para ESE pedido".
    public function test_la_misma_franja_cabe_para_un_pedido_mas_chico(): void
    {
        $this->capacidadDeTodoElDia(24);
        $this->capacidad->reservar($this->tienda, $this->franja('11:00'), 20);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 3);

        $this->assertTrue($franjas[0]['disponible']);
    }

    public function test_llenar_justo_el_cupo_si_cabe(): void
    {
        $this->capacidadDeTodoElDia(24);
        $this->capacidad->reservar($this->tienda, $this->franja('11:00'), 18);

        $this->assertTrue($this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6)[0]['disponible']);
    }

    // Igual que reservar() (#105): sin renglón de capacidad, la tienda no cocina en esa franja.
    public function test_la_franja_sin_capacidad_sale_llena(): void
    {
        $this->capacidadEn('11:10', 24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 6, 2);

        $this->assertSame(0, $franjas[0]['minutos_restantes']);
        $this->assertFalse($franjas[0]['disponible']);
        $this->assertTrue($franjas[1]['disponible']);
    }

    // #237: lo que no se cocina no ocupa cupo, así que cabe en cualquier franja.
    public function test_un_pedido_que_no_se_cocina_cabe_en_cualquier_franja(): void
    {
        $this->capacidadEn('11:10', 24);

        $franjas = $this->capacidad->franjasDisponibles($this->tienda, $this->ahora, 0, 2);

        $this->assertTrue($franjas[0]['disponible']);
        $this->assertTrue($franjas[1]['disponible']);
    }

    public function test_un_dia_sin_capacidad_no_opera(): void
    {
        // Solo hay capacidad el lunes; el martes no
        $this->capacidadDeTodoElDia(24);
        $martes = Carbon::parse('2026-10-27 10:53:00', CapacidadService::ZONA_HORARIA);

        $this->assertCount(0, $this->capacidad->franjasDisponibles($this->tienda, $martes, 6));
    }

    // La app corre en UTC: las 17:53 UTC son las 10:53 en Culiacán.
    public function test_las_horas_son_las_de_la_tienda(): void
    {
        $this->capacidadDeTodoElDia(24);
        $enUtc = Carbon::parse('2026-10-26 17:53:00', 'UTC');

        $this->assertSame('11:00', $this->capacidad->franjasDisponibles($this->tienda, $enUtc, 6)->first()['inicio']);
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

    private function franja(string $hora): Carbon
    {
        return Carbon::parse("2026-10-26 $hora:00", CapacidadService::ZONA_HORARIA);
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
