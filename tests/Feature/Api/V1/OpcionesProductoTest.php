<?php

namespace Tests\Feature\Api\V1;

use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\CategoriaSeeder;
use Database\Seeders\DominiosInstitucionalesSeeder;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Database\Seeders\ProductoSeeder;
use Database\Seeders\TiendaSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Extras y quitables propios de cada producto (HU-04).
class OpcionesProductoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        Categorias::create(['nombre' => 'Bebidas']);

        $this->encargado = Usuario::create([
            'nombre' => 'Encargado',
            'apellido' => 'Pruebas',
            'correo_institucional' => 'encargado@uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
        $this->encargado->asignarRol(Rol::ENCARGADO_TIENDA);
        $this->encargado->correo_verificado_en = now();
        $this->encargado->save();

        Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => $this->encargado->id_facultad,
            'id_dueno' => $this->encargado->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    public function test_el_encargado_crea_un_producto_con_sus_propios_extras_y_quitables(): void
    {
        $this->comoEncargado()->postJson('/api/v1/tienda/productos', [
            ...$this->datosProducto(),
            'extras' => [['nombre' => 'Shot extra', 'precio' => 10]],
            'quitables' => [['nombre' => 'Hielo', 'precio' => 99]],
        ])
            ->assertCreated()
            ->assertJsonPath('datos.extras.0.nombre', 'Shot extra')
            ->assertJsonPath('datos.extras.0.precio', 10)
            ->assertJsonPath('datos.quitables.0.nombre', 'Hielo');

        // Un quitable nunca cuesta, aunque el cliente mande precio.
        $this->assertDatabaseHas('opciones_producto', ['nombre' => 'Hielo', 'tipo' => 'quitable', 'precio' => 0]);
    }

    public function test_al_editar_una_lista_reemplaza_solo_las_de_su_tipo(): void
    {
        $id = $this->comoEncargado()->postJson('/api/v1/tienda/productos', [
            ...$this->datosProducto(),
            'extras' => [['nombre' => 'Shot extra', 'precio' => 10]],
            'quitables' => [['nombre' => 'Hielo']],
        ])->json('datos.id');

        $this->comoEncargado()->patchJson("/api/v1/tienda/productos/{$id}", [
            'extras' => [['nombre' => 'Leche de almendra', 'precio' => 8]],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'datos.extras')
            ->assertJsonPath('datos.extras.0.nombre', 'Leche de almendra')
            ->assertJsonPath('datos.quitables.0.nombre', 'Hielo');
    }

    public function test_un_extra_sin_precio_responde_422(): void
    {
        $this->comoEncargado()->postJson('/api/v1/tienda/productos', [
            ...$this->datosProducto(),
            'extras' => [['nombre' => 'Shot extra']],
        ])
            ->assertStatus(422)
            ->assertJson(['error' => ['campos' => ['extras.0.precio' => ['Cada extra necesita un precio.']]]]);
    }

    public function test_el_seeder_le_da_a_cada_producto_sus_propias_opciones(): void
    {
        $this->seed([
            DominiosInstitucionalesSeeder::class,
            UsuarioSeeder::class,
            TiendaSeeder::class,
            CategoriaSeeder::class,
            ProductoSeeder::class,
        ]);

        $cafe = Producto::withoutGlobalScopes()->where('nombre', 'Café frio')->firstOrFail();
        $nombres = $cafe->opciones->pluck('nombre');

        $this->assertNotContains('Aguacate', $nombres);
        $this->assertNotContains('Cebolla', $nombres);
        $this->assertContains('Shot extra de café', $nombres);
        $this->assertSame('Bebidas', $cafe->categoria->nombre);
    }

    private function comoEncargado(): static
    {
        return $this->withToken($this->encargado->createToken('cliente')->plainTextToken);
    }

    private function datosProducto(): array
    {
        return [
            'nombre' => 'Café frío',
            'id_categoria' => Categorias::firstOrFail()->id,
            'precio' => 35,
            'stock_hoy' => 10,
            'tiempo_prep_min' => 3,
            'tiempo_prep_max' => 5,
        ];
    }
}