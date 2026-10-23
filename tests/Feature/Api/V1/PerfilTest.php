<?php

namespace Tests\Feature\Api\V1;

use App\Models\Facultades;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// GET /auth/yo (#218): el Perfil del usuario con datos frescos del servidor (HU-34).
class PerfilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
    }

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/api/v1/auth/yo')
            ->assertUnauthorized()
            ->assertJsonPath('error.codigo', 'NO_AUTENTICADO');
    }

    public function test_regresa_la_facultad_y_las_faltas(): void
    {
        $usuario = $this->usuario();
        $facultad = Facultades::findOrFail($usuario->id_facultad);

        $this->conSesion($usuario)
            ->getJson('/api/v1/auth/yo')
            ->assertOk()
            ->assertJsonPath('usuario.id', $usuario->id)
            ->assertJsonPath('usuario.rol', 'usuario')
            ->assertJsonPath('usuario.facultad.id', $facultad->id)
            ->assertJsonPath('usuario.facultad.nombre', $facultad->nombre)
            ->assertJsonPath('usuario.faltas', 0);
    }

    // El panel (RutaPorRol y SesionContext) guarda esta respuesta como datos de la sesión:
    // tiene que seguir trayendo todo lo que manda el login.
    public function test_sigue_trayendo_los_campos_de_la_sesion(): void
    {
        $this->conSesion($this->usuario())
            ->getJson('/api/v1/auth/yo')
            ->assertOk()
            ->assertJsonStructure([
                'usuario' => [
                    'id', 'nombre', 'apellido', 'correo_institucional', 'id_facultad',
                    'rol', 'reputacion', 'correo_verificado_en', 'facultad', 'faltas',
                ],
            ]);
    }

    public function test_no_expone_datos_privados(): void
    {
        $this->conSesion($this->usuario())
            ->getJson('/api/v1/auth/yo')
            ->assertOk()
            ->assertJsonMissingPath('usuario.hash_password')
            ->assertJsonMissingPath('usuario.telefono');
    }

    // HU-34, CA-03: los datos vienen del servidor, no de lo que la app guardó al iniciar sesión.
    public function test_trae_los_datos_actuales_y_no_los_del_login(): void
    {
        $usuario = $this->usuario();
        $token = $usuario->createToken('cliente')->plainTextToken;

        $usuario->update(['nombre' => 'Ana María']);

        $this->withToken($token)
            ->getJson('/api/v1/auth/yo')
            ->assertOk()
            ->assertJsonPath('usuario.nombre', 'Ana María');
    }

    private function conSesion(Usuario $usuario): static
    {
        return $this->withToken($usuario->createToken('cliente')->plainTextToken);
    }

    private function usuario(): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }
}