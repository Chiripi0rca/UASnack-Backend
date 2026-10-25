<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoPedido;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CapacidadService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// Tablas pedidos y detalle_pedido (T-45) y CapacidadService::cargaPendiente:
// los minutos que la cocina tiene encima.
class CargaPendienteTest extends TestCase
{
    use RefreshDatabase;

    private CapacidadService $capacidad;

    private Usuario $alumno;

    private Tienda $tienda;

    private int $folio = 2450;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->tienda = $this->tienda('Cafetería');
    }

    public function test_sin_pedidos_la_carga_es_cero(): void
    {
        $this->assertSame(0, $this->capacidad->cargaPendiente($this->tienda));
    }

    public function test_suma_solo_aceptados_y_en_preparacion(): void
    {
        $this->pedido($this->tienda, EstadoPedido::Aceptado, 6);
        $this->pedido($this->tienda, EstadoPedido::EnPreparacion, 12);
        $this->pedido($this->tienda, EstadoPedido::PendientePago, 30);
        $this->pedido($this->tienda, EstadoPedido::EnEspera, 9);
        $this->pedido($this->tienda, EstadoPedido::Listo, 20);
        $this->pedido($this->tienda, EstadoPedido::Entregado, 5);

        $this->assertSame(18, $this->capacidad->cargaPendiente($this->tienda));
    }

    public function test_no_cuenta_los_pedidos_de_otra_tienda(): void
    {
        $this->pedido($this->tienda('Tortas'), EstadoPedido::EnPreparacion, 30);

        $this->assertSame(0, $this->capacidad->cargaPendiente($this->tienda));
    }

    public function test_el_folio_no_se_repite(): void
    {
        $pedido = $this->pedido($this->tienda, EstadoPedido::Aceptado, 6);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pedido($this->tienda, EstadoPedido::Aceptado, 6, $pedido->codigo_pedido);
    }

    public function test_el_pin_no_sale_en_el_json(): void
    {
        $pedido = $this->pedido($this->tienda, EstadoPedido::Listo, 6);

        $this->assertArrayNotHasKey('pin_retiro', $pedido->toArray());
    }

    public function test_las_lineas_guardan_lo_que_se_cobro(): void
    {
        $pedido = $this->pedido($this->tienda, EstadoPedido::PendientePago, 6);
        $producto = Producto::create([
            'id_tienda' => $this->tienda->id,
            'id_categoria' => Categorias::firstOrCreate(['nombre' => 'Bebidas'])->id,
            'nombre' => 'Café frío',
            'precio' => 35,
            'stock_hoy' => 10,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
        ]);

        $pedido->detalle()->create([
            'id_producto' => $producto->id,
            'cantidad' => 2,
            'precio_unitario' => 45,
            'subtotal' => 90,
            'extras' => [['id' => '1', 'nombre' => 'Shot', 'precio' => 10.0]],
            'quitables' => [],
        ]);

        $linea = $pedido->detalle()->first();
        $this->assertSame('Shot', $linea->extras[0]['nombre']);
        $this->assertSame('90.00', $linea->subtotal);
        $this->assertSame($pedido->id, $linea->pedido->id);
    }

    private function tienda(string $nombre): Tienda
    {
        return Tienda::create([
            'nombre' => $nombre,
            'id_facultad' => Facultades::firstOrFail()->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    private function pedido(Tienda $tienda, EstadoPedido $estado, int $minutos, ?string $codigo = null): Pedido
    {
        return Pedido::create([
            'codigo_pedido' => $codigo ?? 'TA-' . ++$this->folio,
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
