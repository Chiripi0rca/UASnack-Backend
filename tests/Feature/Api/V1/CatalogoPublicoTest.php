<?php

namespace Tests\Feature\Api\V1;

use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\CatalogoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

// Pruebas del catálogo que ve el alumno (#230): los endpoints de #45 y #46.
//   GET /tiendas · GET /tiendas/{id}/productos · GET /productos/{id}
//
// La suite no tiene Mongo: la lectura de catalogo_dinamico se simula con
// CatalogoService::documentosDeMongo, y lo demás (SQL, caché, respuesta) es real.
class CatalogoPublicoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $alumno;

    private Facultades $miFacultad;

    private Facultades $otraFacultad;

    private Categorias $bebidas;

    private Categorias $botanas;

    // Lo que "regresa Mongo" en cada prueba: id_producto => personalizaciones
    private array $mongo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->miFacultad = Facultades::firstOrFail();
        $this->otraFacultad = Facultades::create([
            'id_campus' => $this->miFacultad->id_campus,
            'nombre' => 'Facultad de Derecho',
            'clave' => 'DER',
            'activa' => true,
        ]);

        $this->bebidas = Categorias::create(['nombre' => 'Bebidas']);
        $this->botanas = Categorias::create(['nombre' => 'Botanas']);

        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => $this->miFacultad->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $this->partialMock(CatalogoService::class, function (MockInterface $mock) {
            $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive('documentosDeMongo')
                ->andReturnUsing(fn () => collect($this->mongo)
                    ->map(fn (array $personalizaciones) => (object) ['personalizaciones' => $personalizaciones]));
        });
    }

    // --- GET /tiendas ---

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/v1/tiendas')->assertUnauthorized();
        $this->getJson('/api/v1/productos/1')->assertUnauthorized();
    }

    public function test_muestra_las_tiendas_de_todas_las_facultades_con_la_mia_primero(): void
    {
        $this->tienda('Zeta de mi facultad', $this->miFacultad);
        $this->tienda('Alfa de otra facultad', $this->otraFacultad);

        $this->comoAlumno()->getJson('/api/v1/tiendas')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('datos.0.nombre', 'Zeta de mi facultad')
            ->assertJsonPath('datos.0.es_mi_facultad', true)
            ->assertJsonPath('datos.1.nombre', 'Alfa de otra facultad')
            ->assertJsonPath('datos.1.es_mi_facultad', false);
    }

    public function test_una_tienda_dada_de_baja_no_aparece(): void
    {
        $this->tienda('Activa', $this->miFacultad);
        $this->tienda('De baja', $this->miFacultad, activa: false);

        $this->comoAlumno()->getJson('/api/v1/tiendas')
            ->assertOk()
            ->assertJsonCount(1, 'datos')
            ->assertJsonPath('datos.0.nombre', 'Activa');
    }

    public function test_la_tienda_no_expone_datos_internos(): void
    {
        $this->tienda('Cafetería', $this->miFacultad);

        $tienda = $this->comoAlumno()->getJson('/api/v1/tiendas')->json('datos.0');

        $this->assertArrayNotHasKey('id_dueno', $tienda);
        $this->assertArrayNotHasKey('id_cuenta_stripe', $tienda);
        $this->assertArrayHasKey('abierta', $tienda);
    }

    // --- GET /tiendas/{id}/productos ---

    public function test_el_catalogo_trae_productos_con_sus_personalizaciones(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $cafe = $this->producto($tienda, 'Café frío', $this->bebidas);
        $this->mongo = [$cafe->id => [['tipo' => 'extras', 'opciones' => [['id' => '1', 'nombre' => 'Shot', 'precio' => 10]]]]];

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('datos.0.nombre', 'Café frío')
            ->assertJsonPath('datos.0.agotado', false)
            ->assertJsonPath('datos.0.personalizaciones.0.opciones.0.nombre', 'Shot');
    }

    public function test_un_producto_sin_documento_en_mongo_sale_sin_personalizaciones(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $this->producto($tienda, 'Agua', $this->bebidas);

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")
            ->assertOk()
            ->assertJsonPath('datos.0.personalizaciones', []);
    }

    public function test_un_producto_agotado_se_muestra_marcado_y_nunca_se_oculta(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $this->producto($tienda, 'Sin stock', $this->bebidas, stock: 0);
        $this->producto($tienda, 'Marcado agotado', $this->bebidas, estado: 'agotado');

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")
            ->assertOk()
            ->assertJsonCount(2, 'datos')
            ->assertJsonPath('datos.0.agotado', true)
            ->assertJsonPath('datos.1.agotado', true);
    }

    public function test_filtra_por_categoria(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $this->producto($tienda, 'Café', $this->bebidas);
        $this->producto($tienda, 'Papas', $this->botanas);

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos?id_categoria={$this->botanas->id}")
            ->assertOk()
            ->assertJsonCount(1, 'datos')
            ->assertJsonPath('datos.0.nombre', 'Papas');
    }

    public function test_una_categoria_que_no_es_numero_responde_422(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos?id_categoria=abc")
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'VALIDACION');
    }

    public function test_el_alumno_ve_el_catalogo_de_una_tienda_de_otra_facultad(): void
    {
        $tienda = $this->tienda('De otra facultad', $this->otraFacultad);
        $this->producto($tienda, 'Torta', $this->botanas);

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")
            ->assertOk()
            ->assertJsonCount(1, 'datos');
    }

    public function test_tienda_inexistente_o_de_baja_responde_el_mismo_404(): void
    {
        $deBaja = $this->tienda('De baja', $this->miFacultad, activa: false);

        $this->comoAlumno()->getJson('/api/v1/tiendas/999/productos')
            ->assertNotFound()->assertJsonPath('error.codigo', 'NO_ENCONTRADO');

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$deBaja->id}/productos")
            ->assertNotFound()->assertJsonPath('error.codigo', 'NO_ENCONTRADO');
    }

    public function test_si_mongo_no_responde_el_catalogo_responde_503(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $this->producto($tienda, 'Café', $this->bebidas);

        $this->partialMock(CatalogoService::class, function (MockInterface $mock) {
            $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive('documentosDeMongo')
                ->andThrow(new RuntimeException('Mongo caído'));
        });

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")
            ->assertStatus(503)
            ->assertJsonPath('error.codigo', 'CATALOGO_NO_DISPONIBLE');
    }

    public function test_el_catalogo_se_guarda_en_cache(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $this->producto($tienda, 'Café', $this->bebidas);

        $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")->assertOk();

        $this->assertTrue(cache()->has(CatalogoService::claveCache($tienda->id)));
    }

    // --- GET /productos/{id} ---

    public function test_el_detalle_coincide_con_la_lista(): void
    {
        $tienda = $this->tienda('Cafetería', $this->miFacultad);
        $cafe = $this->producto($tienda, 'Café frío', $this->bebidas);

        $lista = $this->comoAlumno()->getJson("/api/v1/tiendas/{$tienda->id}/productos")->json('datos.0');

        $this->comoAlumno()->getJson("/api/v1/productos/{$cafe->id}")
            ->assertOk()
            ->assertExactJson(['datos' => $lista]);
    }

    public function test_el_detalle_de_un_producto_de_tienda_de_baja_responde_404(): void
    {
        $tienda = $this->tienda('De baja', $this->miFacultad, activa: false);
        $cafe = $this->producto($tienda, 'Café', $this->bebidas);

        $this->comoAlumno()->getJson("/api/v1/productos/{$cafe->id}")
            ->assertNotFound()
            ->assertJsonPath('error.codigo', 'NO_ENCONTRADO');

        $this->comoAlumno()->getJson('/api/v1/productos/999')->assertNotFound();
    }

    private function comoAlumno(): static
    {
        return $this->withToken($this->alumno->createToken('cliente')->plainTextToken);
    }

    private function tienda(string $nombre, Facultades $facultad, bool $activa = true): Tienda
    {
        $tienda = Tienda::create([
            'nombre' => $nombre,
            'id_facultad' => $facultad->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);

        // activa no está en $fillable: el alta y baja es del administrador.
        $tienda->activa = $activa;
        $tienda->save();

        return $tienda;
    }

    private function producto(
        Tienda $tienda,
        string $nombre,
        Categorias $categoria,
        int $stock = 10,
        string $estado = 'disponible',
    ): Producto {
        return Producto::create([
            'id_tienda' => $tienda->id,
            'id_categoria' => $categoria->id,
            'nombre' => $nombre,
            'precio' => 30,
            'stock_hoy' => $stock,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
            'estado_producto' => $estado,
        ]);
    }
}
