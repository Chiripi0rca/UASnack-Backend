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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// POST /tienda/productos/{id}/foto (#238): el encargado sube la foto de un producto de su tienda.
//
// Storage::fake('public') cambia el disco por una carpeta temporal que se borra sola:
// las pruebas nunca escriben en tu storage/app/public de verdad.
class FotoProductoTest extends TestCase
{
    use RefreshDatabase;

    // Un PNG real de 1×1 píxel. Se usa en vez de UploadedFile::fake()->image() porque esa
    // función necesita la extensión GD de PHP, y el contenedor no la tiene.
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Usuario $encargado;

    private Tienda $miTienda;

    private Tienda $otraTienda;

    private Producto $torta;

    protected function setUp(): void
    {
        parent::setUp();

        // No hay Mongo en la suite: el job queda en la cola falsa y se revisa que se encoló.
        Queue::fake();
        // Disco "public" falso, en una carpeta temporal.
        Storage::fake('public');

        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->encargado = $this->usuario('encargado@uas.edu.mx');
        $this->miTienda = $this->tienda($this->encargado);
        $this->otraTienda = $this->tienda($this->usuario('otro@uas.edu.mx'));
        $this->torta = $this->producto($this->miTienda);
    }

    public function test_sube_la_foto_y_guarda_su_url(): void
    {
        $respuesta = $this->comoEncargado()
            ->postJson("/api/v1/tienda/productos/{$this->torta->id}/foto", ['foto' => $this->foto()])
            ->assertOk()
            ->assertJsonPath('datos.id', $this->torta->id);

        // La URL que regresó es la que quedó guardada en el producto...
        $url = $respuesta->json('datos.foto_url');
        $this->assertSame($url, $this->torta->fresh()->foto_url);
        // ...y apunta a un archivo que de verdad existe, en la carpeta de su tienda.
        $this->assertCount(1, Storage::disk('public')->files("productos/{$this->miTienda->id}"));
        $this->assertStringEndsWith('.png', $url);
    }

    public function test_cambiar_la_foto_borra_la_anterior(): void
    {
        $ruta = "/api/v1/tienda/productos/{$this->torta->id}/foto";
        $this->comoEncargado()->postJson($ruta, ['foto' => $this->foto()])->assertOk();
        $this->comoEncargado()->postJson($ruta, ['foto' => $this->foto()])->assertOk();

        // Dos subidas, pero en el disco solo queda la última.
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_un_archivo_que_no_es_imagen_responde_422(): void
    {
        $pdf = UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf');

        $this->comoEncargado()
            ->postJson("/api/v1/tienda/productos/{$this->torta->id}/foto", ['foto' => $pdf])
            ->assertStatus(422)
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.foto.0', 'El archivo debe ser una imagen.');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_una_foto_de_mas_de_2_mb_responde_422(): void
    {
        // Un PNG válido con relleno al final hasta pasar de 2 MB.
        $pesada = UploadedFile::fake()->createWithContent(
            'pesada.png',
            base64_decode(self::PNG_1X1).str_repeat("\0", 2100 * 1024)
        );

        $this->comoEncargado()
            ->postJson("/api/v1/tienda/productos/{$this->torta->id}/foto", ['foto' => $pesada])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.foto.0', 'La foto no puede pesar más de 2 MB.');
    }

    public function test_sin_archivo_responde_422(): void
    {
        $this->comoEncargado()
            ->postJson("/api/v1/tienda/productos/{$this->torta->id}/foto", [])
            ->assertStatus(422)
            ->assertJsonPath('error.campos.foto.0', 'Elige una foto de máximo 2 MB.');
    }

    public function test_un_producto_de_otra_tienda_responde_404_y_no_guarda_nada(): void
    {
        $ajeno = $this->producto($this->otraTienda);

        $this->comoEncargado()
            ->postJson("/api/v1/tienda/productos/{$ajeno->id}/foto", ['foto' => $this->foto()])
            ->assertNotFound();

        $this->assertNull($ajeno->fresh()->foto_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_limpia_la_cache_del_catalogo_y_encola_la_copia_a_mongo(): void
    {
        // Como si el catálogo de la tienda ya estuviera en caché.
        Cache::put(CatalogoService::claveCache($this->miTienda->id), ['catalogo viejo']);

        $this->comoEncargado()
            ->postJson("/api/v1/tienda/productos/{$this->torta->id}/foto", ['foto' => $this->foto()])
            ->assertOk();

        $this->assertFalse(Cache::has(CatalogoService::claveCache($this->miTienda->id)));
        Queue::assertPushed(EspejarProductoEnMongo::class);
    }

    // --- Ayudantes ---

    // Una foto PNG válida, lista para mandarse en la petición.
    private function foto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('torta.png', base64_decode(self::PNG_1X1));
    }

    // Las peticiones llevan el token del encargado, como lo haría el panel.
    private function comoEncargado(): static
    {
        return $this->withToken($this->encargado->createToken('cliente')->plainTextToken);
    }

    // Un encargado de tienda con el correo verificado.
    private function usuario(string $correo): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        $usuario->asignarRol(Rol::ENCARGADO_TIENDA);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }

    // Una tienda cuyo dueño es ese encargado.
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

    // Un producto de esa tienda, sin foto.
    private function producto(Tienda $tienda): Producto
    {
        return Producto::withoutGlobalScopes()->create([
            'id_tienda' => $tienda->id,
            'id_categoria' => Categorias::firstOrCreate(['nombre' => 'Comida rápida'])->id,
            'nombre' => 'Torta de milanesa',
            'precio' => 45,
            'stock_hoy' => 10,
            'tiempo_prep_min' => 5,
            'tiempo_prep_max' => 8,
        ]);
    }
}
