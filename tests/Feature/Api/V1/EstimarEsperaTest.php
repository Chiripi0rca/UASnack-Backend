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

// #56: CapacidadService::estimarEspera, el rango de espera del modo "voy saliendo" (§7.4):
//   estimado = cargaPendiente() / capacidad + minutos del pedido
//   rango    = de estimado a estimado × 1.4
class EstimarEsperaTest extends TestCase
{
    use RefreshDatabase;

    private CapacidadService $capacidad;

    private Usuario $alumno;

    private Tienda $tienda;

    private int $folio = 5600;

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes 11:05 en Culiacán: cae dentro de la franja 11:00 a 11:10 que se crea abajo.
        Carbon::setTestNow(Carbon::parse('2026-10-26 11:05:00', CapacidadService::ZONA_HORARIA));

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->capacidad = app(CapacidadService::class);

        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        // Franjas de 10 min, umbral de 15 min y 3 "voy saliendo" a la vez.
        $this->tienda = $this->tienda('Cafetería');

        // Capacidad de la franja de ahorita: 10 min de cocina cada 10 min reales = 1.0
        $this->capacidadAhorita($this->tienda, 10);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_con_la_cocina_vacia_el_estimado_es_solo_mi_pedido(): void
    {
        $espera = $this->capacidad->estimarEspera($this->tienda, 6);

        // 0 / 1.0 + 6 = 6  →  6 a 8 (6 × 1.4 = 8.4)
        $this->assertSame(6, $espera['min']);
        $this->assertSame(8, $espera['max']);
        $this->assertFalse($espera['saturada']);
        $this->assertSame(0, $espera['pedidos_en_cocina']);
        $this->assertTrue($espera['voy_saliendo_disponible']);
    }

    public function test_el_rango_crece_con_los_pedidos_en_cocina(): void
    {
        $this->pedido($this->tienda, EstadoPedido::Aceptado, 6);
        $this->pedido($this->tienda, EstadoPedido::EnPreparacion, 8);

        $espera = $this->capacidad->estimarEspera($this->tienda, 6);

        // 14 / 1.0 + 6 = 20  →  20 a 28
        $this->assertSame(20, $espera['min']);
        $this->assertSame(28, $espera['max']);
        $this->assertSame(2, $espera['pedidos_en_cocina']);
    }

    public function test_la_carga_sube_el_rango_respecto_a_la_cocina_vacia(): void
    {
        $vacia = $this->capacidad->estimarEspera($this->tienda, 6);

        $this->pedido($this->tienda, EstadoPedido::EnPreparacion, 10);
        $conCarga = $this->capacidad->estimarEspera($this->tienda, 6);

        $this->assertGreaterThan($vacia['min'], $conCarga['min']);
        $this->assertGreaterThan($vacia['max'], $conCarga['max']);
    }

    public function test_mas_capacidad_baja_la_espera(): void
    {
        // 20 min de cocina cada 10 min reales = 2.0
        $this->capacidadAhorita($this->tienda, 20);
        $this->pedido($this->tienda, EstadoPedido::Aceptado, 14);

        $espera = $this->capacidad->estimarEspera($this->tienda, 6);

        // 14 / 2.0 + 6 = 13  →  13 a 18 (13 × 1.4 = 18.2)
        $this->assertSame(13, $espera['min']);
        $this->assertSame(18, $espera['max']);
    }

    public function test_sin_renglon_de_capacidad_usa_uno(): void
    {
        $sinCapacidad = $this->tienda('Tortas');
        $this->pedido($sinCapacidad, EstadoPedido::Aceptado, 10);

        $espera = $this->capacidad->estimarEspera($sinCapacidad, 0);

        // Sin renglón la capacidad vale 1.0: 10 / 1.0 + 0 = 10  →  10 a 14. Y no truena por dividir entre 0.
        $this->assertSame(10, $espera['min']);
        $this->assertSame(14, $espera['max']);
    }

    public function test_siempre_es_un_rango_nunca_un_punto(): void
    {
        // Cocina vacía y un pedido que no se cocina (#237): el estimado es 0.
        $espera = $this->capacidad->estimarEspera($this->tienda, 0);

        $this->assertSame(0, $espera['min']);
        $this->assertGreaterThan($espera['min'], $espera['max']);
    }

    public function test_saturada_cuando_el_maximo_pasa_del_umbral(): void
    {
        // Mi pedido de 10 min en cocina vacía: 10 a 14.
        $this->tienda->update(['umbral_espera_min' => 14]);
        $this->assertFalse($this->capacidad->estimarEspera($this->tienda, 10)['saturada'], 'max igual al umbral no es saturada');

        $this->tienda->update(['umbral_espera_min' => 13]);
        $this->assertTrue($this->capacidad->estimarEspera($this->tienda, 10)['saturada'], 'max arriba del umbral sí');
    }

    public function test_voy_saliendo_se_cierra_al_llegar_al_cupo(): void
    {
        $this->pedido($this->tienda, EstadoPedido::EnEspera, 5);
        $this->pedido($this->tienda, EstadoPedido::EnEspera, 5);
        $this->assertTrue($this->capacidad->estimarEspera($this->tienda, 5)['voy_saliendo_disponible'], '2 de 3 todavía deja pasar');

        $this->pedido($this->tienda, EstadoPedido::EnEspera, 5);
        $this->assertFalse($this->capacidad->estimarEspera($this->tienda, 5)['voy_saliendo_disponible'], '3 de 3 ya no');
    }

    public function test_los_pedidos_en_espera_no_cuentan_como_carga(): void
    {
        $this->pedido($this->tienda, EstadoPedido::EnEspera, 30);

        $espera = $this->capacidad->estimarEspera($this->tienda, 6);

        $this->assertSame(6, $espera['min']);
        $this->assertSame(0, $espera['pedidos_en_cocina']);
    }

    public function test_no_cuenta_los_pedidos_de_otra_tienda(): void
    {
        $this->pedido($this->tienda('Tortas'), EstadoPedido::EnPreparacion, 30);

        $espera = $this->capacidad->estimarEspera($this->tienda, 6);

        $this->assertSame(6, $espera['min']);
        $this->assertSame(0, $espera['pedidos_en_cocina']);
    }

    private function tienda(string $nombre): Tienda
    {
        return Tienda::create([
            'nombre' => $nombre,
            'id_facultad' => Facultades::firstOrFail()->id,
            'duracion_franja_min' => 10,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    // Capacidad de la franja 11:00 a 11:10 del lunes (día 1), que es la de "ahorita".
    private function capacidadAhorita(Tienda $tienda, int $minutosCocina): void
    {
        CapacidadFranja::withoutGlobalScopes()->updateOrCreate(
            ['id_tienda' => $tienda->id, 'dia_semana' => 1, 'hora_inicio' => '11:00:00'],
            ['hora_fin' => '11:10:00', 'minutos_cocina' => $minutosCocina],
        );
    }

    private function pedido(Tienda $tienda, EstadoPedido $estado, int $minutos): Pedido
    {
        return Pedido::create([
            'codigo_pedido' => 'TE-' . ++$this->folio,
            'clave_idempotencia' => (string) Str::uuid(),
            'id_usuario' => $this->alumno->id,
            'id_tienda' => $tienda->id,
            'estado' => $estado,
            'modo_retiro' => Pedido::RETIRO_FRANJA,
            'total' => 45,
            'minutos_cocina' => $minutos,
            'pin_retiro' => '0420',
        ]);
    }
}
