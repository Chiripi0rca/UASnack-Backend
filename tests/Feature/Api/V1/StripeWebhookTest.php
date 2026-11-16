<?php

namespace Tests\Feature\Api\V1;

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

// #146 (HU-24): POST /webhooks/stripe verifica la firma y atiende account.updated.
// Stripe se simula con CuentasStripe; la firma se calcula igual que Stripe, con el secreto de prueba.
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'whsec_prueba';

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        config(['services.stripe.webhook_secret' => self::SECRETO]);

        $dueno = Usuario::create([
            'nombre' => 'Usuario',
            'apellido' => 'Pruebas',
            'correo_institucional' => 'encargado@uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
        $dueno->asignarRol(Rol::ENCARGADO_TIENDA);
        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => $dueno->id_facultad,
            'id_dueno' => $dueno->id,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
        $this->tienda->id_cuenta_stripe = 'acct_123';
        $this->tienda->save();
    }

    public function test_sin_firma_o_con_firma_falsa_responde_400(): void
    {
        $cuerpo = $this->cuerpo('account.updated', 'acct_123');

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [], $cuerpo)
            ->assertStatus(400)
            ->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');

        $this->enviar($cuerpo, 't=1,v1=falsa')
            ->assertStatus(400)
            ->assertJsonPath('error.codigo', 'FIRMA_INVALIDA');
    }

    public function test_si_cambian_el_cuerpo_despues_de_firmar_responde_400(): void
    {
        $firma = $this->firma($this->cuerpo('account.updated', 'acct_123'));

        $this->enviar($this->cuerpo('account.updated', 'acct_999'), $firma)->assertStatus(400);
    }

    public function test_sin_secreto_configurado_responde_503(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $cuerpo = $this->cuerpo('account.updated', 'acct_123');

        $this->enviar($cuerpo, $this->firma($cuerpo))
            ->assertStatus(503)
            ->assertJsonPath('error.codigo', 'STRIPE_SIN_CONFIGURAR');
    }

    public function test_account_updated_habilita_la_cuenta_cuando_stripe_dice_que_ya_puede_cobrar(): void
    {
        $this->simularStripe('acct_123', true);

        $this->avisar('account.updated', 'acct_123')->assertOk();

        $this->assertTrue($this->tienda->fresh()->cuenta_habilitada);
    }

    public function test_account_updated_deshabilita_la_cuenta_si_ya_no_puede_cobrar(): void
    {
        $this->tienda->cuenta_habilitada = true;
        $this->tienda->save();
        $this->simularStripe('acct_123', false);

        $this->avisar('account.updated', 'acct_123')->assertOk();

        $this->assertFalse($this->tienda->fresh()->cuenta_habilitada);
    }

    public function test_cuenta_desconocida_responde_200_sin_preguntar_a_stripe(): void
    {
        $this->mock(CuentasStripe::class, fn (MockInterface $m) => $m->shouldNotReceive('consultarCuenta'));

        $this->avisar('account.updated', 'acct_otra')->assertOk();
    }

    public function test_evento_que_no_conocemos_responde_200_y_no_toca_nada(): void
    {
        $this->mock(CuentasStripe::class, fn (MockInterface $m) => $m->shouldNotReceive('consultarCuenta'));

        $this->avisar('customer.created', 'acct_123')->assertOk();

        $this->assertFalse((bool) $this->tienda->fresh()->cuenta_habilitada);
    }

    // --- Ayudas ---

    private function simularStripe(string $idCuenta, bool $puedeCobrar): void
    {
        $this->mock(CuentasStripe::class, function (MockInterface $m) use ($idCuenta, $puedeCobrar) {
            $m->shouldReceive('consultarCuenta')
                ->once()
                ->with($idCuenta)
                ->andReturn(['puede_cobrar' => $puedeCobrar, 'rechazada' => false]);
        });
    }

    private function cuerpo(string $tipo, string $idCuenta): string
    {
        return json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $tipo,
            'account' => $idCuenta,
            'data' => ['object' => ['id' => $idCuenta, 'object' => 'account']],
        ]);
    }

    // Misma firma que arma Stripe: t=<tiempo>,v1=HMAC-SHA256("<tiempo>.<cuerpo>", secreto)
    private function firma(string $cuerpo): string
    {
        $t = time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$cuerpo}", self::SECRETO);
    }

    private function enviar(string $cuerpo, string $firma)
    {
        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $firma,
            'CONTENT_TYPE' => 'application/json',
        ], $cuerpo);
    }

    private function avisar(string $tipo, string $idCuenta)
    {
        $cuerpo = $this->cuerpo($tipo, $idCuenta);

        return $this->enviar($cuerpo, $this->firma($cuerpo));
    }
}
