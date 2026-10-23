<?php

namespace Tests\Feature\Api\V1;
use App\Exceptions\ErrorNegocio;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\OpcionProducto;
use App\Models\Producto;
use App\Models\Tienda;
use App\Services\CatalogoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// CatalogoService::calcularTotal (#49): el total lo calcula el servidor con precios de PostgreSQL.
class CalcularTotalTest extends TestCase
{
    use RefreshDatabase;

    private CatalogoService $catalogo;

    private Producto $cafe;

    private OpcionProducto $shot;

    private OpcionProducto $almendra;

    private OpcionProducto $sinHielo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->catalogo = app(CatalogoService::class);

        $this->cafe = $this->producto($this->tienda('Cafetería'), 'Café frío', 35.10);
        $this->shot = $this->cafe->opciones()->create(['tipo' => OpcionProducto::EXTRA, 'nombre' => 'Shot', 'precio' => 10.10]);
        $this->almendra = $this->cafe->opciones()->create(['tipo' => OpcionProducto::EXTRA, 'nombre' => 'Almendra', 'precio' => 8]);
        $this->sinHielo = $this->cafe->opciones()->create(['tipo' => OpcionProducto::QUITABLE, 'nombre' => 'Hielo']);
    }

    public function test_suma_precio_mas_extras_por_cantidad(): void
    {
        $resultado = $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 3, 'extras' => [(string) $this->shot->id, $this->almendra->id]],
        ]);

        // (35.10 + 10.10 + 8) × 3 = 159.60, sin errores de punto flotante
        $this->assertSame(159.6, $resultado['total']);
        $this->assertSame(53.2, $resultado['lineas'][0]['precio_unitario']);
        $this->assertSame(159.6, $resultado['lineas'][0]['subtotal']);
        $this->assertCount(2, $resultado['lineas'][0]['extras']);
    }

    public function test_los_quitables_no_cuestan(): void
    {
        $resultado = $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1, 'quitables' => [$this->sinHielo->id]],
        ]);

        $this->assertSame(35.1, $resultado['total']);
        $this->assertSame('Hielo', $resultado['lineas'][0]['quitables'][0]['nombre']);
    }

    public function test_ignora_el_precio_y_el_total_que_manda_el_cliente(): void
    {
        $resultado = $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1, 'precio' => 0.01, 'total' => 0.01],
        ]);

        $this->assertSame(35.1, $resultado['total']);
    }

    public function test_rechaza_un_extra_de_otro_producto(): void
    {
        $papas = $this->producto($this->cafe->tienda, 'Papas', 30);
        $queso = $papas->opciones()->create(['tipo' => OpcionProducto::EXTRA, 'nombre' => 'Queso', 'precio' => 10]);

        $this->assertErrorNegocio('OPCION_INVALIDA', fn () => $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1, 'extras' => [$queso->id]],
        ]));
    }

    public function test_rechaza_un_quitable_mandado_como_extra(): void
    {
        $this->assertErrorNegocio('OPCION_INVALIDA', fn () => $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1, 'extras' => [$this->sinHielo->id]],
        ]));
    }

    public function test_rechaza_productos_de_dos_tiendas(): void
    {
        $otro = $this->producto($this->tienda('Otra tienda'), 'Torta', 45);

        $this->assertErrorNegocio('VARIAS_TIENDAS', fn () => $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1],
            ['id_producto' => $otro->id, 'cantidad' => 1],
        ]));
    }

    public function test_rechaza_carrito_vacio_cantidad_cero_y_producto_inexistente(): void
    {
        $this->assertErrorNegocio('CARRITO_VACIO', fn () => $this->catalogo->calcularTotal([]));
        $this->assertErrorNegocio('CANTIDAD_INVALIDA', fn () => $this->catalogo->calcularTotal([
            ['id_producto' => $this->cafe->id, 'cantidad' => 0],
        ]));
        $this->assertErrorNegocio('NO_ENCONTRADO', fn () => $this->catalogo->calcularTotal([
            ['id_producto' => 999, 'cantidad' => 1],
        ]));
    }

    private function assertErrorNegocio(string $codigo, callable $accion): void
    {
        try {
            $accion();
            $this->fail("Se esperaba el error {$codigo}");
        } catch (ErrorNegocio $e) {
            $this->assertSame($codigo, $e->codigo);
        }
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

    private function producto(Tienda $tienda, string $nombre, float $precio): Producto
    {
        return Producto::create([
            'id_tienda' => $tienda->id,
            'id_categoria' => Categorias::firstOrCreate(['nombre' => 'Bebidas'])->id,
            'nombre' => $nombre,
            'precio' => $precio,
            'stock_hoy' => 10,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
        ]);
    }
}
