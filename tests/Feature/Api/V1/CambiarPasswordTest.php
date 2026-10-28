<?php

namespace Tests\Feature\Api\V1;

use App\Models\Facultades;
use App\Models\Usuario;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// HU-33, CA-05: POST /auth/cambiar-password, con sesión y escribiendo la contraseña actual.
class CambiarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);

        $this->usuario = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('vieja12345'),
            'telefono' => '6671234567',
        ]);
        $this->usuario->correo_verificado_en = now();
        $this->usuario->save();
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->postJson('/api/v1/auth/cambiar-password', $this->datos('vieja12345', 'nueva12345'))
            ->assertUnauthorized();
    }

    public function test_con_la_contrasena_actual_correcta_la_cambia(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/auth/cambiar-password', $this->datos('vieja12345', 'nueva12345'))
            ->assertNoContent();

        $this->assertTrue(Hash::check('nueva12345', $this->usuario->fresh()->hash_password));
    }

    public function test_con_la_contrasena_actual_incorrecta_no_cambia_nada(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/auth/cambiar-password', $this->datos('equivocada1', 'nueva12345'))
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'PASSWORD_ACTUAL_INCORRECTA');

        $this->assertTrue(Hash::check('vieja12345', $this->usuario->fresh()->hash_password));
    }

    // Las otras sesiones se cierran; la de este dispositivo sigue abierta
    public function test_cierra_las_demas_sesiones_y_deja_la_actual(): void
    {
        $otro = $this->usuario->createToken('cliente')->accessToken;
        $actual = $this->usuario->createToken('cliente');

        $this->withToken($actual->plainTextToken)
            ->postJson('/api/v1/auth/cambiar-password', $this->datos('vieja12345', 'nueva12345'))
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otro->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $actual->accessToken->id]);
    }

    public function test_la_nueva_debe_coincidir_y_ser_distinta(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/auth/cambiar-password', [
                'password_actual' => 'vieja12345',
                'password' => 'vieja12345',
                'password_confirmation' => 'otracosa1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'VALIDACION')
            ->assertJsonPath('error.campos.password', [
                'Las contraseñas no coinciden.',
                'La contraseña nueva debe ser distinta de la actual.',
            ]);
    }

    private function token(): string
    {
        return $this->usuario->createToken('cliente')->plainTextToken;
    }

    private function datos(string $actual, string $nueva): array
    {
        return ['password_actual' => $actual, 'password' => $nueva, 'password_confirmation' => $nueva];
    }
}
