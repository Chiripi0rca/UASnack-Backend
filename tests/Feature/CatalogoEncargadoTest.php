<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\EspejarProductoEnMongo;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CatalogoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Pruebas del catálogo del encargado (#225) sobre los endpoints reales de /tienda/productos:
//   #99  CRUD de productos y stock
//   #163 aislamiento entre tiendas (lo ajeno responde 404, no 403)
//   #165 copia a Mongo (en cola) y limpieza de la caché del catálogo
class CatalogoEncargadoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargado;

    private Tienda $miTienda;

    private Tienda $otraTienda;

    private Categorias $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        // No hay Mongo en la suite: el job queda en la cola falsa y se revisa que se encoló.
        Queue::fake();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->categoria = Categorias::create(['nombre' => 'Bebidas']);

        $this->encargado = $this->usuario('encargado@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $this->miTienda = $this->tienda($this->encargado);
        $this->otraTienda = $this->tienda($this->usuario('otro@uas.edu.mx', Rol::ENCARGADO_TIENDA));
    }

    // --- #99: listar ---

    public function test_lista_solo_los_productos_de_su_tienda_agotados_incluidos(): void
    {
        $this->producto($this->miTienda, 'Agua', stock: 0, estado: 'agotado');
        $this->producto($this->miTienda, 'Café');
        $this->producto($this->otraTienda, 'Ajeno');

        $this->comoEncargado()->getJson('/api/v1/tienda/productos')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('datos.0.nombre', 'Agua')
            ->assertJsonPath('datos.0.estado_producto', 'agotado')
            ->assertJsonPath('datos.1.nombre', 'Café');
    }

    // --- #99: crear ---

    public function test_crea_en_su_tienda_y_el_servidor_decide_tienda_y_estado(): void
    {
        $id = $this->comoEncargado()->postJson('/api/v1/tienda/productos', [
            ...$this->datosProducto(),
            'stock_hoy' => 0,
            // Se ignoran: vienen del encargado y del stock, nunca del cliente.
            'id_tienda' => $this->otraTienda->id,
            'estado_producto' => 'disponible',
        ])
            ->assertCreated()
            ->assertJsonPath('datos.precio', 35)
            ->assertJsonPath('datos.estado_producto', 'agotado')
            ->json('datos.id');

        $this->assertDatabaseHas('productos', ['id' => $id, 'id_tienda' => $this->miTienda->id]);
    }

    public function test_crear_valida_los_campos(): void
    {
        $this->comoEncargado()->postJson('/api/v1/tienda/productos', [
            'precio' => -1,
            'id_categoria' => 999,
            'tiempo_prep_min' => 10,
            'tiempo_prep_max' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.nombre.0', 'El nombre es obligatorio.')
            ->assertJsonPath('error.campos.precio.0', 'El precio no puede ser negativo.')
            ->assertJsonPath('error.campos.id_categoria.0', 'La categoría no existe.')
            ->assertJsonPath('error.campos.tiempo_prep_max.0', 'El tiempo máximo no puede ser menor que el mínimo.');
    }

    // --- #99: editar y stock ---

    public function test_edita_solo_lo_que_llega(): void
    {
        $producto = $this->producto($this->miTienda, 'Café');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$producto->id}", ['precio' => 40])
            ->assertOk()
            ->assertJsonPath('datos.precio', 40)
            ->assertJsonPath('datos.nombre', 'Café');
    }

    public function test_editar_revisa_los_tiempos_mezclados_con_lo_que_ya_tenia(): void
    {
        // El producto tiene tiempo_prep_min = 3; un máximo de 2 no puede quedar.
        $producto = $this->producto($this->miTienda, 'Café');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$producto->id}", ['tiempo_prep_max' => 2])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.tiempo_prep_max.0', 'El tiempo máximo no puede ser menor que el mínimo.');
    }

    public function test_stock_en_cero_lo_agota_y_reponerlo_lo_vuelve_a_activar(): void
    {
        $producto = $this->producto($this->miTienda, 'Café');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$producto->id}/stock", ['stock_hoy' => 0])
            ->assertOk()
            ->assertJsonPath('datos.stock_hoy', 0)
            ->assertJsonPath('datos.estado_producto', 'agotado');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$producto->id}/stock", ['stock_hoy' => 5])
            ->assertOk()
            ->assertJsonPath('datos.estado_producto', 'disponible');
    }

    public function test_el_stock_no_puede_ser_negativo(): void
    {
        $producto = $this->producto($this->miTienda, 'Café');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$producto->id}/stock", ['stock_hoy' => -1])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.stock_hoy.0', 'El stock no puede ser negativo.');
    }

    // --- #163: aislamiento ---

    public function test_un_producto_de_otra_tienda_responde_404_al_editarlo_o_cambiarle_stock(): void
    {
        $ajeno = $this->producto($this->otraTienda, 'Ajeno');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$ajeno->id}", ['precio' => 1])
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$ajeno->id}/stock", ['stock_hoy' => 0])
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');

        $this->assertDatabaseHas('productos', ['id' => $ajeno->id, 'precio' => 30, 'stock_hoy' => 10]);
        Queue::assertNothingPushed();
    }

    public function test_un_alumno_no_entra_al_catalogo_del_encargado(): void
    {
        $alumno = $this->usuario('alumno@uas.edu.mx', Rol::USUARIO);

        $this->withToken($alumno->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/tienda/productos')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_PERMISO');
    }

    public function test_un_encargado_sin_tienda_no_entra(): void
    {
        $sinTienda = $this->usuario('sin.tienda@uas.edu.mx', Rol::ENCARGADO_TIENDA);

        $this->withToken($sinTienda->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/tienda/productos')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_TIENDA_ASIGNADA');
    }

    // --- #165: copia a Mongo y caché ---

    public function test_crear_editar_y_cambiar_stock_copian_a_mongo_y_limpian_la_cache(): void
    {
        $clave = CatalogoService::claveCache($this->miTienda->id);

        Cache::put($clave, ['viejo']);
        $id = $this->comoEncargado()->postJson('/api/v1/tienda/productos', $this->datosProducto())
            ->assertCreated()->json('datos.id');
        $this->assertFalse(Cache::has($clave), 'crear debe borrar la caché');

        Cache::put($clave, ['viejo']);
        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$id}", ['nombre' => 'Café helado'])->assertOk();
        $this->assertFalse(Cache::has($clave), 'editar debe borrar la caché');

        Cache::put($clave, ['viejo']);
        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$id}/stock", ['stock_hoy' => 0])->assertOk();
        $this->assertFalse(Cache::has($clave), 'cambiar stock debe borrar la caché');

        Queue::assertPushed(EspejarProductoEnMongo::class, 3);
        Queue::assertPushed(EspejarProductoEnMongo::class, fn (EspejarProductoEnMongo $job) => $job->idProducto === $id);
    }

    public function test_solo_se_limpia_la_cache_de_su_tienda(): void
    {
        $claveAjena = CatalogoService::claveCache($this->otraTienda->id);
        Cache::put($claveAjena, ['catálogo ajeno']);

        $this->comoEncargado()->postJson('/api/v1/tienda/productos', $this->datosProducto())->assertCreated();

        $this->assertTrue(Cache::has($claveAjena));
    }

    public function test_si_la_validacion_falla_no_se_copia_nada_a_mongo(): void
    {
        $this->comoEncargado()->postJson('/api/v1/tienda/productos', [])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    private function comoEncargado(): static
    {
        return $this->withToken($this->encargado->createToken('cliente')->plainTextToken);
    }

    private function datosProducto(): array
    {
        return [
            'nombre' => 'Café frío',
            'id_categoria' => $this->categoria->id,
            'precio' => 35,
            'stock_hoy' => 10,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
        ];
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $usuario->asignarRol($rol);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }

    private function tienda(Usuario $encargado): Tienda
    {
        return Tienda::create([
            'nombre' => "Tienda de {$encargado->correo_institucional}",
            'id_facultad' => $encargado->id_facultad,
            'id_dueno' => $encargado->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    private function producto(Tienda $tienda, string $nombre, int $stock = 10, string $estado = 'disponible'): Producto
    {
        return Producto::withoutGlobalScopes()->create([
            'id_tienda' => $tienda->id,
            'id_categoria' => $this->categoria->id,
            'nombre' => $nombre,
            'precio' => 30,
            'stock_hoy' => $stock,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
            'estado_producto' => $estado,
        ]);
    }
}
