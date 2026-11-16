<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoProducto;
use App\Exceptions\ErrorNegocio;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Tienda;
use App\Services\CatalogoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// CatalogoService::verificarStock y descontarStock (#192).
// la carrera de dos alumnos a la vez no se puede probar con sqlite en memoria;
// se prueba la regla que la resuelve: el descuento solo baja si alcanza.
class StockPedidoTest extends TestCase
{
    use RefreshDatabase;

    private CatalogoService $catalogo;

    private Tienda $tienda;

    private Producto $cafe;

    private Producto $torta;

    protected function setUp(): void
    {
        parent::setUp();

        // sincronizarMongo manda un job a la cola: aquí no hay mongo
        Queue::fake();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->catalogo = app(CatalogoService::class);

        $this->tienda = $this->tienda('Cafetería');
        $this->cafe = $this->producto('Café frío', 5);
        $this->torta = $this->producto('Torta', 2);
    }

    // verificarStock

    public function test_verificar_pasa_cuando_alcanza_para_todo(): void
    {
        $this->catalogo->verificarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 5],
            ['id_producto' => $this->torta->id, 'cantidad' => 1],
        ]);

        // sin excepción = alcanza; verificar no descuenta nada
        $this->assertSame(5, $this->cafe->fresh()->stock_hoy);
        $this->assertSame(2, $this->torta->fresh()->stock_hoy);
    }

    public function test_verificar_falla_si_se_piden_mas_unidades_de_las_que_hay(): void
    {
        $this->assertErrorNegocio('STOCK_INSUFICIENTE', 409, fn () => $this->catalogo->verificarStock([
            ['id_producto' => $this->torta->id, 'cantidad' => 3],
        ]));
    }

    public function test_verificar_nombra_todos_los_productos_que_no_alcanzan(): void
    {
        try {
            $this->catalogo->verificarStock([
                ['id_producto' => $this->cafe->id, 'cantidad' => 6],
                ['id_producto' => $this->torta->id, 'cantidad' => 3],
            ]);
            $this->fail('Se esperaba STOCK_INSUFICIENTE');
        } catch (ErrorNegocio $e) {
            $this->assertStringContainsString('Café frío', $e->getMessage());
            $this->assertStringContainsString('Torta', $e->getMessage());
        }
    }

    public function test_verificar_suma_las_lineas_repetidas_del_mismo_producto(): void
    {
        // 3 + 3 son 6 cafés y solo hay 5
        $this->assertErrorNegocio('STOCK_INSUFICIENTE', 409, fn () => $this->catalogo->verificarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 3, 'extras' => ['1']],
            ['id_producto' => $this->cafe->id, 'cantidad' => 3, 'extras' => ['2']],
        ]));
    }

    public function test_verificar_rechaza_un_producto_marcado_agotado_aunque_tenga_stock(): void
    {
        $this->cafe->update(['estado_producto' => EstadoProducto::Agotado->value]);

        $this->assertErrorNegocio('STOCK_INSUFICIENTE', 409, fn () => $this->catalogo->verificarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1],
        ]));
    }

    // descontarStock

    public function test_descontar_baja_el_stock_de_cada_producto(): void
    {
        $this->catalogo->descontarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 2],
            ['id_producto' => $this->torta->id, 'cantidad' => 1],
        ]);

        $this->assertSame(3, $this->cafe->fresh()->stock_hoy);
        $this->assertSame(1, $this->torta->fresh()->stock_hoy);
        $this->assertSame(EstadoProducto::Disponible->value, $this->cafe->fresh()->estado_producto);
    }

    public function test_descontar_suma_las_lineas_repetidas_del_mismo_producto(): void
    {
        $this->catalogo->descontarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 2, 'extras' => ['1']],
            ['id_producto' => $this->cafe->id, 'cantidad' => 2, 'extras' => ['2']],
        ]);

        $this->assertSame(1, $this->cafe->fresh()->stock_hoy);
    }

    public function test_descontar_marca_agotado_cuando_el_stock_llega_a_cero(): void
    {
        $this->catalogo->descontarStock([
            ['id_producto' => $this->torta->id, 'cantidad' => 2],
        ]);

        $torta = $this->torta->fresh();
        $this->assertSame(0, $torta->stock_hoy);
        $this->assertSame(EstadoProducto::Agotado->value, $torta->estado_producto);
    }

    public function test_el_segundo_pedido_de_la_ultima_unidad_recibe_el_error(): void
    {
        $ultima = [['id_producto' => $this->torta->id, 'cantidad' => 2]];

        $this->catalogo->descontarStock($ultima);

        $this->assertErrorNegocio('STOCK_INSUFICIENTE', 409, fn () => $this->catalogo->descontarStock($ultima));
        $this->assertSame(0, $this->torta->fresh()->stock_hoy, 'el stock no puede quedar negativo');
    }

    public function test_descontar_es_todo_o_nada(): void
    {
        // el café alcanza, la torta no: el café tampoco se descuenta
        $this->assertErrorNegocio('STOCK_INSUFICIENTE', 409, fn () => $this->catalogo->descontarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 2],
            ['id_producto' => $this->torta->id, 'cantidad' => 3],
        ]));

        $this->assertSame(5, $this->cafe->fresh()->stock_hoy);
        $this->assertSame(2, $this->torta->fresh()->stock_hoy);
    }

    public function test_descontar_nunca_acepta_cantidades_que_subirian_el_stock(): void
    {
        $this->assertErrorNegocio('CANTIDAD_INVALIDA', 422, fn () => $this->catalogo->descontarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => -3],
        ]));
        $this->assertErrorNegocio('CANTIDAD_INVALIDA', 422, fn () => $this->catalogo->descontarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 0],
        ]));

        $this->assertSame(5, $this->cafe->fresh()->stock_hoy);
    }

    public function test_descontar_borra_la_cache_del_catalogo_de_la_tienda(): void
    {
        $clave = CatalogoService::claveCache($this->tienda->id);
        Cache::put($clave, ['viejo']);

        $this->catalogo->descontarStock([
            ['id_producto' => $this->cafe->id, 'cantidad' => 1],
        ]);

        $this->assertFalse(Cache::has($clave), 'el alumno debe ver el stock nuevo al momento');
    }

    // lo que comparten las dos

    public function test_carrito_vacio_y_producto_inexistente(): void
    {
        foreach (['verificarStock', 'descontarStock'] as $metodo) {
            $this->assertErrorNegocio('CARRITO_VACIO', 422, fn () => $this->catalogo->$metodo([]));
            $this->assertErrorNegocio('NO_ENCONTRADO', 404, fn () => $this->catalogo->$metodo([
                ['id_producto' => 999, 'cantidad' => 1],
            ]));
        }
    }

    private function assertErrorNegocio(string $codigo, int $status, callable $accion): void
    {
        try {
            $accion();
            $this->fail("Se esperaba el error {$codigo}");
        } catch (ErrorNegocio $e) {
            $this->assertSame($codigo, $e->codigo);
            $this->assertSame($status, $e->status);
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

    private function producto(string $nombre, int $stock): Producto
    {
        return Producto::create([
            'id_tienda' => $this->tienda->id,
            'id_categoria' => Categorias::firstOrCreate(['nombre' => 'Bebidas'])->id,
            'nombre' => $nombre,
            'precio' => 30,
            'stock_hoy' => $stock,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
        ]);
    }
}
