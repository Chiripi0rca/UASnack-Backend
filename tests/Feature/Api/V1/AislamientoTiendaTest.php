<?php

namespace Tests\Feature\Api\V1;

use App\Models\Rol;
use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Prueba el aislamiento entre tiendas (#163): un encargado solo ve y toca lo de su tienda,
// lo ajeno responde 404 (no 403), y el alumno sigue viendo el catálogo de todas las tiendas.
class AislamientoTiendaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargadoA;

    private Producto $productoA;

    private Producto $productoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Rutas de prueba con los mismos middlewares que el bloque /tienda de routes/api.php.
        // {producto} se busca con route model binding, igual que lo hará #99.
        Route::prefix('api/v1/prueba-tienda')
            ->middleware(['api', 'auth:sanctum', 'rol:encargado_tienda', 'tenant'])
            ->group(function () {
                Route::get('/productos', fn () => response()->json(Producto::pluck('id')));
                Route::get('/productos/{producto}', fn (Producto $producto) => response()->json(['id' => $producto->id]));
            });

        // Ruta como la del catálogo del alumno: solo auth:sanctum, sin "tenant".
        Route::middleware(['api', 'auth:sanctum'])
            ->get('/api/v1/prueba-catalogo', fn () => response()->json(Producto::pluck('id')));

        $categoria = Categorias::create(['nombre' => 'Bebidas']);

        $this->encargadoA = $this->usuario(Rol::ENCARGADO_TIENDA, 'encargado.a@uas.edu.mx');
        $encargadoB = $this->usuario(Rol::ENCARGADO_TIENDA, 'encargado.b@uas.edu.mx');

        $this->productoA = $this->producto($this->tiendaDe($this->encargadoA), $categoria);
        $this->productoB = $this->producto($this->tiendaDe($encargadoB), $categoria);
    }

    public function test_el_encargado_ve_un_producto_de_su_tienda(): void
    {
        $this->withToken($this->tokenDe($this->encargadoA))
            ->getJson("/api/v1/prueba-tienda/productos/{$this->productoA->id}")
            ->assertOk()
            ->assertJsonPath('id', $this->productoA->id);
    }

    public function test_un_producto_de_otra_tienda_responde_404_y_no_403(): void
    {
        $this->withToken($this->tokenDe($this->encargadoA))
            ->getJson("/api/v1/prueba-tienda/productos/{$this->productoB->id}")
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    public function test_la_lista_del_encargado_solo_trae_su_tienda(): void
    {
        $this->withToken($this->tokenDe($this->encargadoA))
            ->getJson('/api/v1/prueba-tienda/productos')
            ->assertOk()
            ->assertExactJson([$this->productoA->id]);
    }

    public function test_el_alumno_ve_los_productos_de_todas_las_tiendas(): void
    {
        $alumno = $this->usuario(Rol::USUARIO, 'alumno@info.uas.edu.mx');

        $this->withToken($this->tokenDe($alumno))
            ->getJson('/api/v1/prueba-catalogo')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_la_policy_niega_como_404_editar_un_producto_ajeno(): void
    {
        $respuestaAjeno = Gate::forUser($this->encargadoA)->inspect('update', $this->productoB);
        $respuestaPropio = Gate::forUser($this->encargadoA)->inspect('update', $this->productoA);

        $this->assertTrue($respuestaAjeno->denied());
        $this->assertSame(404, $respuestaAjeno->status());
        $this->assertTrue($respuestaPropio->allowed());
    }

    private function usuario(string $rol, string $correo): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Prueba',
            'apellido' => 'UASnack',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::first()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        // rol y correo_verificado_en no están en $fillable (nunca vienen del cliente)
        $usuario->asignarRol($rol);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }

    private function tiendaDe(Usuario $encargado): Tienda
    {
        return Tienda::create([
            'nombre' => "Tienda de {$encargado->correo_institucional}",
            'id_facultad' => $encargado->id_facultad,
            'id_dueno' => $encargado->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    private function producto(Tienda $tienda, Categorias $categoria): Producto
    {
        return Producto::create([
            'id_tienda' => $tienda->id,
            'id_categoria' => $categoria->id,
            'nombre' => 'Agua de jamaica',
            'precio' => 25,
            'stock_hoy' => 10,
            'tiempo_prep_min' => 1,
            'tiempo_prep_max' => 2,
        ]);
    }

    private function tokenDe(Usuario $usuario): string
    {
        return $usuario->createToken('cliente')->plainTextToken;
    }
}
