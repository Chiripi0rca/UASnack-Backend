<?php

namespace Tests\Feature\Api\V1;

use App\Mail\CodigoRecuperacionMail;
use App\Mail\CodigoVerificacionMail;
use App\Models\Facultades;
use App\Models\Usuario;
use App\Models\VerificacionCorreo;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// HU-33: recuperar la contraseña olvidada en dos pasos.
//   POST /auth/olvide-password      → manda el código
//   POST /auth/restablecer-password → código + contraseña nueva, cierra todas las sesiones
class RecuperarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO = 'ana@info.uas.edu.mx';

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        Mail::fake();

        $this->usuario = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => self::CORREO,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('vieja12345'),
            'telefono' => '6671234567',
        ]);
        $this->usuario->correo_verificado_en = now();
        $this->usuario->save();
    }

    // --- Paso 1: olvide-password ---

    public function test_manda_el_codigo_al_correo(): void
    {
        $this->postJson('/api/v1/auth/olvide-password', ['correo_institucional' => self::CORREO])
            ->assertNoContent();

        Mail::assertSent(CodigoRecuperacionMail::class, fn ($mail) => $mail->hasTo(self::CORREO)
            && preg_match('/^\d{6}$/', $mail->codigo) === 1);
    }

    // CA-02: misma respuesta exista o no la cuenta
    public function test_un_correo_que_no_existe_responde_igual_y_no_manda_nada(): void
    {
        $this->postJson('/api/v1/auth/olvide-password', ['correo_institucional' => 'nadie@info.uas.edu.mx'])
            ->assertNoContent();

        Mail::assertNothingSent();
    }

    public function test_el_correo_con_mayusculas_tambien_sirve(): void
    {
        $this->postJson('/api/v1/auth/olvide-password', ['correo_institucional' => 'ANA@info.uas.edu.mx'])
            ->assertNoContent();

        Mail::assertSent(CodigoRecuperacionMail::class);
    }

    public function test_pedirlo_dos_veces_seguidas_no_manda_dos_correos(): void
    {
        $this->pedirCodigo();
        $this->postJson('/api/v1/auth/olvide-password', ['correo_institucional' => self::CORREO])
            ->assertNoContent();

        Mail::assertSent(CodigoRecuperacionMail::class, 1);
    }

    public function test_un_correo_mal_escrito_responde_422(): void
    {
        $this->postJson('/api/v1/auth/olvide-password', ['correo_institucional' => 'no-es-correo'])
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'VALIDACION');
    }

    // --- Paso 2: restablecer-password ---

    public function test_con_el_codigo_correcto_cambia_la_contrasena(): void
    {
        $codigo = $this->pedirCodigo();

        $this->restablecer($codigo, 'nueva12345')->assertNoContent();

        $this->postJson('/api/v1/auth/login', ['correo_institucional' => self::CORREO, 'password' => 'nueva12345'])
            ->assertOk();
    }

    // CA-04: se cierran todas las sesiones abiertas
    public function test_restablecer_cierra_todas_las_sesiones(): void
    {
        $this->usuario->createToken('cliente');
        $this->usuario->createToken('cliente');
        $codigo = $this->pedirCodigo();

        $this->restablecer($codigo, 'nueva12345')->assertNoContent();

        $this->assertSame(0, $this->usuario->tokens()->count());
    }

    public function test_el_codigo_solo_sirve_una_vez(): void
    {
        $codigo = $this->pedirCodigo();
        $this->restablecer($codigo, 'nueva12345')->assertNoContent();

        $this->restablecer($codigo, 'otra123456')
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'CODIGO_EXPIRADO');
    }

    public function test_un_codigo_incorrecto_responde_codigo_invalido(): void
    {
        $codigo = $this->pedirCodigo();

        $this->restablecer($this->otroCodigo($codigo), 'nueva12345')
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'CODIGO_INVALIDO');

        $this->assertTrue(Hash::check('vieja12345', $this->usuario->fresh()->hash_password));
    }

    public function test_despues_de_5_intentos_se_bloquea(): void
    {
        $codigo = $this->pedirCodigo();
        VerificacionCorreo::where('tipo', 'recuperacion')->update(['intentos' => 5]);

        // Ni el código correcto pasa: hay que pedir otro
        $this->restablecer($codigo, 'nueva12345')
            ->assertStatus(429)
            ->assertJsonPath('error.codigo', 'DEMASIADOS_INTENTOS');
    }

    public function test_el_codigo_vence_a_los_10_minutos(): void
    {
        $codigo = $this->pedirCodigo();
        $this->travel(11)->minutes();

        $this->restablecer($codigo, 'nueva12345')
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'CODIGO_EXPIRADO');
    }

    // Cada código es para lo suyo: el de verificar el correo no sirve para cambiar la contraseña
    public function test_un_codigo_de_verificacion_no_sirve_para_recuperar(): void
    {
        VerificacionCorreo::create([
            'id_usuario' => $this->usuario->id,
            'tipo' => 'verificacion',
            'codigo_hash' => Hash::make('123456'),
            'expira_en' => now()->addMinutes(10),
        ]);

        $this->restablecer('123456', 'nueva12345')
            ->assertUnprocessable()
            ->assertJsonPath('error.codigo', 'CODIGO_EXPIRADO');
    }

    // Mismo error que un código malo: no revela qué correos existen
    public function test_un_correo_que_no_existe_responde_codigo_invalido(): void
    {
        $this->postJson('/api/v1/auth/restablecer-password', [
            'correo_institucional' => 'nadie@info.uas.edu.mx',
            'codigo' => '123456',
            'password' => 'nueva12345',
            'password_confirmation' => 'nueva12345',
        ])->assertUnprocessable()->assertJsonPath('error.codigo', 'CODIGO_INVALIDO');
    }

    public function test_las_contrasenas_deben_coincidir_y_tener_8_caracteres(): void
    {
        $this->postJson('/api/v1/auth/restablecer-password', [
            'correo_institucional' => self::CORREO,
            'codigo' => '123456',
            'password' => 'corta',
            'password_confirmation' => 'otra',
        ])->assertUnprocessable()
            ->assertJsonPath('error.campos.password.0', 'Usa al menos 8 caracteres.');
    }

    // Recibir el código prueba que el correo es suyo
    public function test_restablecer_deja_verificado_un_correo_sin_verificar(): void
    {
        $this->usuario->correo_verificado_en = null;
        $this->usuario->save();
        $codigo = $this->pedirCodigo();

        $this->restablecer($codigo, 'nueva12345')->assertNoContent();

        $this->assertNotNull($this->usuario->fresh()->correo_verificado_en);
    }

    // --- Ayudas ---

    private function pedirCodigo(): string
    {
        $this->postJson('/api/v1/auth/olvide-password', ['correo_institucional' => self::CORREO])->assertNoContent();

        $codigo = null;
        Mail::assertSent(CodigoRecuperacionMail::class, function ($mail) use (&$codigo) {
            $codigo = $mail->codigo;

            return true;
        });
        Mail::assertNotSent(CodigoVerificacionMail::class);

        return $codigo;
    }

    private function restablecer(string $codigo, string $password)
    {
        return $this->postJson('/api/v1/auth/restablecer-password', [
            'correo_institucional' => self::CORREO,
            'codigo' => $codigo,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function otroCodigo(string $codigo): string
    {
        return $codigo === '111111' ? '222222' : '111111';
    }
}
