<?php

namespace Tests\Feature\Api\V1;

use App\Models\Categorias;
use App\Models\Facultades;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// #237: productos que no se cocinan (botanas, refrescos). Se guardan con tiempo de preparación 0
// y las respuestas traen se_prepara = false para que la app no muestre tiempo.
class ProductoSinPreparacionTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargado;

    private Tienda $tienda;

    private Categorias $botanas;

    protected function setUp(): void
    {
        parent::setUp();

        // No hay Mongo en la suite: la copia al catálogo queda en la cola falsa
        Queue::fake();
        $this->seed(JerarquiaInstitucionalSeeder::class);
        $this->botanas = Categorias::create(['nombre' => 'Botanas']);

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

        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => $this->encargado->id_facultad,
            'id_dueno' => $this->encargado->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    public function test_se_puede_crear_un_producto_que_no_se_cocina(): void
    {
        $this->comoEncargado()
            ->postJson('/api/v1/tienda/productos', $this->datos(0, 0))
            ->assertCreated()
            ->assertJsonPath('datos.tiempo_prep_min', 0)
            ->assertJsonPath('datos.tiempo_prep_max', 0)
            ->assertJsonPath('datos.se_prepara', false);
    }

    public function test_un_producto_que_se_cocina_sigue_igual(): void
    {
        $this->comoEncargado()
            ->postJson('/api/v1/tienda/productos', $this->datos(5, 8))
            ->assertCreated()
            ->assertJsonPath('datos.se_prepara', true);
    }

    // "De 0 a 10 minutos" no tiene sentido: si no se cocina, el máximo también es 0
    public function test_si_el_minimo_es_0_el_maximo_tambien(): void
    {
        $this->comoEncargado()
            ->postJson('/api/v1/tienda/productos', $this->datos(0, 10))
            ->assertUnprocessable()
            ->assertJsonPath('error.campos.tiempo_prep_max.0', 'Si el producto no se cocina, el tiempo máximo también va en 0.');
    }

    public function test_los_tiempos_no_pueden_ser_negativos(): void
    {
        $this->comoEncargado()
            ->postJson('/api/v1/tienda/productos', $this->datos(-1, 5))
            ->assertUnprocessable()
            ->assertJsonPath('error.campos.tiempo_prep_min.0', 'El tiempo de preparación no puede ser negativo.');
    }

    public function test_editar_un_producto_para_que_ya_no_se_cocine(): void
    {
        $producto = $this->producto(5, 8);

        $this->comoEncargado()
            ->patchJson("/api/v1/tienda/productos/{$producto->id}", ['tiempo_prep_min' => 0, 'tiempo_prep_max' => 0])
            ->assertOk()
            ->assertJsonPath('datos.se_prepara', false);
    }

    // Se revisa con lo que ya tenía: mandar solo el mínimo en 0 dejaría "0 a 8"
    public function test_editar_solo_el_minimo_a_0_no_deja_un_maximo_mayor(): void
    {
        $producto = $this->producto(5, 8);

        $this->comoEncargado()
            ->patchJson("/api/v1/tienda/productos/{$producto->id}", ['tiempo_prep_min' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('error.campos.tiempo_prep_max.0', 'Si el producto no se cocina, el tiempo máximo también va en 0.');
    }

    public function test_la_lista_del_panel_trae_se_prepara(): void
    {
        $this->producto(0, 0);

        $this->comoEncargado()
            ->getJson('/api/v1/tienda/productos')
            ->assertOk()
            ->assertJsonPath('datos.0.se_prepara', false);
    }

    private function comoEncargado(): static
    {
        return $this->withToken($this->encargado->createToken('cliente')->plainTextToken);
    }

    private function datos(int $minimo, int $maximo): array
    {
        return [
            'nombre' => 'Papas',
            'id_categoria' => $this->botanas->id,
            'precio' => 20,
            'stock_hoy' => 10,
            'tiempo_prep_min' => $minimo,
            'tiempo_prep_max' => $maximo,
        ];
    }

    private function producto(int $minimo, int $maximo): Producto
    {
        return Producto::withoutGlobalScopes()->create([
            'id_tienda' => $this->tienda->id,
            'id_categoria' => $this->botanas->id,
            'nombre' => 'Papas',
            'precio' => 20,
            'stock_hoy' => 10,
            'tiempo_prep_min' => $minimo,
            'tiempo_prep_max' => $maximo,
            'estado_producto' => 'disponible',
        ]);
    }
}
