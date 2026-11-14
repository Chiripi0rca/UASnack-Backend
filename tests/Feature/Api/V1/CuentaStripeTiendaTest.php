<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Models\Facultades;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\Stripe\CuentasStripe;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

// #109 (HU-24): la cuenta de Stripe de la tienda y su enlace de alta.
//   POST /tienda/pagos/onboarding → { datos: { url } }
//   GET  /tienda/pagos/estado     → { datos: { estado } }
// Stripe se simula con CuentasStripe: estas pruebas nunca llaman a la API real.
class CuentaStripeTiendaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $encargado;

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        config(['app.panel_url' => 'http://localhost:5174/panel']);

        $this->encargado = $this->usuario('encargado@uas.edu.mx', Rol::ENCARGADO_TIENDA);
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => $this->encargado->id_facultad,
            'id_dueno' => $this->encargado->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    // --- Onboarding ---

    public function test_la_primera_vez_crea_la_cuenta_la_guarda_y_regresa_el_enlace(): void
    {
        $this->simularStripe(function (MockInterface $stripe) {
            $stripe->shouldReceive('crearCuenta')->once()->andReturn('acct_123');
            $stripe->shouldReceive('crearEnlaceDeAlta')
                ->once()
                ->with('acct_123', 'http://localhost:5174/panel/pagos', 'http://localhost:5174/panel/pagos')
                ->andReturn('https://connect.stripe.com/setup/e/acct_123/abc');
        });

        $this->comoEncargado()->postJson('/api/v1/tienda/pagos/onboarding')
            ->assertOk()
            ->assertExactJson(['datos' => ['url' => 'https://connect.stripe.com/setup/e/acct_123/abc']]);

        $this->assertSame('acct_123', $this->tienda->fresh()->id_cuenta_stripe);
    }

    // Si vuelve a tocar el botón (o el enlace caducó), se usa la misma cuenta: nunca se crea otra
    public function test_si_ya_tiene_cuenta_no_crea_otra_solo_un_enlace_nuevo(): void
    {
        $this->conCuenta('acct_existente');
        $this->simularStripe(function (MockInterface $stripe) {
            $stripe->shouldNotReceive('crearCuenta');
            $stripe->shouldReceive('crearEnlaceDeAlta')->once()
                ->with('acct_existente', \Mockery::any(), \Mockery::any())
                ->andReturn('https://connect.stripe.com/setup/e/nuevo');
        });

        $this->comoEncargado()->postJson('/api/v1/tienda/pagos/onboarding')
            ->assertOk()
            ->assertJsonPath('datos.url', 'https://connect.stripe.com/setup/e/nuevo');
    }

    // Sin llave de Stripe en el .env no se llama a Stripe: se avisa con el formato de siempre
    public function test_sin_llave_de_stripe_responde_503(): void
    {
        config(['services.stripe.secret' => null]);

        $this->comoEncargado()->postJson('/api/v1/tienda/pagos/onboarding')
            ->assertStatus(503)
            ->assertJsonPath('error.codigo', 'STRIPE_SIN_CONFIGURAR');

        $this->assertNull($this->tienda->fresh()->id_cuenta_stripe);
    }

    public function test_si_stripe_falla_responde_502_y_no_guarda_nada(): void
    {
        $this->simularStripe(function (MockInterface $stripe) {
            $stripe->shouldReceive('crearCuenta')->once()->andThrow(
                new ErrorNegocio('STRIPE_NO_DISPONIBLE', 'No pudimos conectar con Stripe.', 502)
            );
        });

        $this->comoEncargado()->postJson('/api/v1/tienda/pagos/onboarding')
            ->assertStatus(502)
            ->assertJsonPath('error.codigo', 'STRIPE_NO_DISPONIBLE');

        $this->assertNull($this->tienda->fresh()->id_cuenta_stripe);
    }

    // --- Estado ---

    // Sin cuenta todavía: pendiente, sin preguntarle a Stripe
    public function test_sin_cuenta_el_estado_es_pendiente(): void
    {
        $this->simularStripe(fn (MockInterface $stripe) => $stripe->shouldNotReceive('consultarCuenta'));

        $this->comoEncargado()->getJson('/api/v1/tienda/pagos/estado')
            ->assertOk()
            ->assertExactJson(['datos' => ['estado' => 'pendiente']]);
    }

    public function test_si_stripe_ya_le_permite_cobrar_esta_verificada_y_se_habilita(): void
    {
        $this->conCuenta('acct_123');
        $this->simularStripe(fn (MockInterface $stripe) => $stripe->shouldReceive('consultarCuenta')
            ->once()->with('acct_123')
            ->andReturn(['puede_cobrar' => true, 'rechazada' => false]));

        $this->comoEncargado()->getJson('/api/v1/tienda/pagos/estado')
            ->assertOk()
            ->assertExactJson(['datos' => ['estado' => 'verificado']]);

        $this->assertTrue($this->tienda->fresh()->cuenta_habilitada);
    }

    public function test_si_falta_terminar_el_alta_esta_pendiente(): void
    {
        $this->conCuenta('acct_123');
        $this->simularStripe(fn (MockInterface $stripe) => $stripe->shouldReceive('consultarCuenta')
            ->andReturn(['puede_cobrar' => false, 'rechazada' => false]));

        $this->comoEncargado()->getJson('/api/v1/tienda/pagos/estado')
            ->assertExactJson(['datos' => ['estado' => 'pendiente']]);

        $this->assertFalse($this->tienda->fresh()->cuenta_habilitada);
    }

    // Si Stripe la rechaza, la tienda deja de poder recibir pedidos aunque antes estuviera habilitada
    public function test_si_stripe_la_rechazo_esta_rechazada_y_se_deshabilita(): void
    {
        $this->conCuenta('acct_123', habilitada: true);
        $this->simularStripe(fn (MockInterface $stripe) => $stripe->shouldReceive('consultarCuenta')
            ->andReturn(['puede_cobrar' => false, 'rechazada' => true]));

        $this->comoEncargado()->getJson('/api/v1/tienda/pagos/estado')
            ->assertExactJson(['datos' => ['estado' => 'rechazado']]);

        $this->assertFalse($this->tienda->fresh()->cuenta_habilitada);
    }

    // --- Quién entra ---

    public function test_un_alumno_no_entra(): void
    {
        $alumno = $this->usuario('alumno@uas.edu.mx', Rol::USUARIO);

        $this->withToken($alumno->createToken('cliente')->plainTextToken)
            ->getJson('/api/v1/tienda/pagos/estado')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_PERMISO');
    }

    public function test_un_encargado_sin_tienda_no_entra(): void
    {
        $sinTienda = $this->usuario('sintienda@uas.edu.mx', Rol::ENCARGADO_TIENDA);

        $this->withToken($sinTienda->createToken('cliente')->plainTextToken)
            ->postJson('/api/v1/tienda/pagos/onboarding')
            ->assertForbidden()
            ->assertJsonPath('error.codigo', 'SIN_TIENDA_ASIGNADA');
    }

    // --- Ayudas ---

    private function simularStripe(callable $comoResponde): void
    {
        config(['services.stripe.secret' => 'sk_test_simulada']);
        $this->mock(CuentasStripe::class, $comoResponde);
    }

    private function conCuenta(string $idCuenta, bool $habilitada = false): void
    {
        $this->tienda->id_cuenta_stripe = $idCuenta;
        $this->tienda->cuenta_habilitada = $habilitada;
        $this->tienda->save();
    }

    private function comoEncargado(): static
    {
        return $this->withToken($this->encargado->createToken('cliente')->plainTextToken);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        $usuario = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => $correo,
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
        $usuario->asignarRol($rol);
        $usuario->correo_verificado_en = now();
        $usuario->save();

        return $usuario;
    }
}
