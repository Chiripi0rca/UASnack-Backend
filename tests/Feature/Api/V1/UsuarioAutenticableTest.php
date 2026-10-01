<?php

namespace Tests\Feature\Api\V1;

use App\Models\Facultades;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Prueba que Usuario funciona como el modelo autenticable del sistema (tarea #157):
// que el provider de config/auth.php lo encuentra y que $request->user() devuelve un Usuario.
class UsuarioAutenticableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Ruta de prueba que regresa la clase y el id del usuario autenticado por el token.
        Route::middleware('auth:sanctum')->get('/api/v1/prueba-usuario-autenticado', fn () => response()->json([
            'clase' => get_class(request()->user()),
            'id' => request()->user()->id,
        ]));
    }

    public function test_el_provider_de_auth_valida_la_contrasena_contra_hash_password(): void
    {
        $usuario = $this->crearUsuario();
        $provider = Auth::createUserProvider('users');

        // retrieveByCredentials busca por cualquier campo que no sea la contraseña.
        $encontrado = $provider->retrieveByCredentials(['correo_institucional' => 'ana@info.uas.edu.mx']);

        $this->assertTrue($encontrado->is($usuario));
        $this->assertTrue($provider->validateCredentials($encontrado, ['password' => 'secreta123']));
        $this->assertFalse($provider->validateCredentials($encontrado, ['password' => 'otra-clave']));
    }

    public function test_el_token_de_sanctum_resuelve_a_un_usuario(): void
    {
        $usuario = $this->crearUsuario();

        $this->withToken($usuario->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/prueba-usuario-autenticado')
            ->assertOk()
            ->assertJson(['clase' => Usuario::class, 'id' => $usuario->id]);
    }

    public function test_hash_password_sigue_oculto_al_serializar(): void
    {
        $this->assertArrayNotHasKey('hash_password', $this->crearUsuario()->toArray());
    }

    public function test_el_login_y_el_logout_siguen_funcionando(): void
    {
        $this->crearUsuario();

        $token = $this->postJson('/api/v1/auth/login', [
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'password' => 'secreta123',
        ])->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        // Se olvida el usuario que quedó en memoria para que la siguiente petición vuelva a revisar el token.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('error.codigo', 'NO_AUTENTICADO');
    }

    // Crea un usuario ya verificado con contraseña conocida.
    private function crearUsuario(): Usuario
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
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }
}
