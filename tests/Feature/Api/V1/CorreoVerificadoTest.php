<?php

namespace Tests\Feature\Api\V1;

use App\Models\Facultades;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Prueba el middleware CorreoVerificado sin depender de que ya exista POST /pedidos:
// registra una ruta de prueba con los mismos middlewares que tendrá la ruta real.
class CorreoVerificadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        Route::middleware(['auth:sanctum', 'correo.verificado'])
            ->post('/api/v1/prueba-correo-verificado', fn () => response()->noContent());
    }

    public function test_sin_correo_verificado_responde_403_con_el_codigo_del_proyecto(): void
    {
        $this->withToken($this->tokenDe(verificado: false))
            ->postJson('/api/v1/prueba-correo-verificado')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'CORREO_NO_VERIFICADO');
    }

    public function test_con_correo_verificado_deja_pasar_la_peticion(): void
    {
        $this->withToken($this->tokenDe(verificado: true))
            ->postJson('/api/v1/prueba-correo-verificado')
            ->assertNoContent();
    }

    public function test_sin_token_responde_401_antes_de_revisar_el_correo(): void
    {
        $this->postJson('/api/v1/prueba-correo-verificado')
            ->assertUnauthorized()
            ->assertJsonPath('error.codigo', 'NO_AUTENTICADO');
    }

    // Crea un usuario y le genera un token real de Sanctum, igual que el login.
    private function tokenDe(bool $verificado): string
    {
        $usuario = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::first()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);

        // correo_verificado_en no está en $fillable (nunca viene del cliente), por eso se asigna aparte.
        $usuario->correo_verificado_en = $verificado ? now() : null;
        $usuario->save();

        return $usuario->createToken('cliente')->plainTextToken;
    }
}