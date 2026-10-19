<?php

namespace Tests\Feature\Api\V1;

use App\Models\Rol;
use App\Models\Facultades;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Support\Tenant;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Prueba los middlewares "rol" y "tenant" (#162) con rutas de prueba que llevan los mismos
// middlewares que los bloques /admin y /tienda de routes/api.php.
class RolYTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        // Cada ruta responde lo que quedó en Tenant, para comprobar qué resolvió el middleware.
        $responderTenant = fn (Tenant $tenant) => response()->json([
            'id_facultad' => $tenant->idFacultad,
            'id_tienda' => $tenant->idTienda,
        ]);

        Route::middleware(['auth:sanctum', 'rol:administrador', 'tenant'])
            ->get('/api/v1/prueba-admin', $responderTenant);

        Route::middleware(['auth:sanctum', 'rol:encargado_tienda', 'tenant'])
            ->get('/api/v1/prueba-tienda', $responderTenant);

        Route::middleware(['auth:sanctum', 'rol:encargado_tienda,administrador'])
            ->get('/api/v1/prueba-dos-roles', fn () => response()->noContent());
    }

    public function test_un_alumno_no_entra_al_panel_de_administracion(): void
    {
        $this->withToken($this->tokenDe($this->usuario(Rol::USUARIO)))
            ->getJson('/api/v1/prueba-admin')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_PERMISO');
    }

    public function test_un_encargado_no_entra_al_panel_de_administracion(): void
    {
        $encargado = $this->usuario(Rol::ENCARGADO_TIENDA, 'encargado@uas.edu.mx');
        $this->tiendaDe($encargado);

        $this->withToken($this->tokenDe($encargado))
            ->getJson('/api/v1/prueba-admin')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_PERMISO');
    }

    public function test_el_administrador_entra_y_queda_resuelta_su_facultad(): void
    {
        $admin = $this->usuario(Rol::ADMINISTRADOR, 'admin@uas.edu.mx');

        $this->withToken($this->tokenDe($admin))
            ->getJson('/api/v1/prueba-admin')
            ->assertOk()
            ->assertJsonPath('id_facultad', $admin->id_facultad)
            ->assertJsonPath('id_tienda', null);
    }

    public function test_el_encargado_entra_y_queda_resuelta_su_tienda(): void
    {
        $encargado = $this->usuario(Rol::ENCARGADO_TIENDA, 'encargado@uas.edu.mx');
        $tienda = $this->tiendaDe($encargado);

        $this->withToken($this->tokenDe($encargado))
            ->getJson('/api/v1/prueba-tienda')
            ->assertOk()
            ->assertJsonPath('id_tienda', $tienda->id);
    }

    public function test_un_encargado_sin_tienda_asignada_no_entra_al_panel(): void
    {
        $encargado = $this->usuario(Rol::ENCARGADO_TIENDA, 'encargado@uas.edu.mx');

        $this->withToken($this->tokenDe($encargado))
            ->getJson('/api/v1/prueba-tienda')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_TIENDA_ASIGNADA');
    }

    public function test_una_ruta_puede_aceptar_varios_roles(): void
    {
        $admin = $this->usuario(Rol::ADMINISTRADOR, 'admin@uas.edu.mx');
        $alumno = $this->usuario(Rol::USUARIO);

        $this->withToken($this->tokenDe($admin))->getJson('/api/v1/prueba-dos-roles')->assertNoContent();

        // Se limpia la sesión de la prueba antes de cambiar de usuario
        $this->app['auth']->forgetGuards();

        $this->withToken($this->tokenDe($alumno))->getJson('/api/v1/prueba-dos-roles')->assertForbidden();
    }

    public function test_sin_token_responde_401_antes_de_revisar_el_rol(): void
    {
        $this->getJson('/api/v1/prueba-admin')
            ->assertUnauthorized()
            ->assertJsonPath('error.codigo', 'NO_AUTENTICADO');
    }

    private function usuario(string $rol, string $correo = 'ana@info.uas.edu.mx'): Usuario
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
            'nombre' => 'Cafetería de prueba',
            'id_facultad' => $encargado->id_facultad,
            'id_dueno' => $encargado->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    private function tokenDe(Usuario $usuario): string
    {
        return $usuario->createToken('cliente')->plainTextToken;
    }
}
