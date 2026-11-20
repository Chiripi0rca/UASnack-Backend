<?php

namespace Tests\Feature\Api\V1;

use App\Enums\EstadoPago;
use App\Enums\EstadoPedido;
use App\Models\Facultades;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\PedidoService;
use Database\Seeders\JerarquiaInstitucionalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

// #62 (HU-07): Stripe avisa si el cobro salió bien o mal y el pedido avanza o se queda esperando.
//   payment_intent.succeeded      → pago "pagado" y pedido PAGADO (EN_ESPERA en "voy saliendo")
//   payment_intent.payment_failed → pago "fallido" y el pedido sigue en PENDIENTE_PAGO (CA-02)
// Los avisos llegan firmados igual que los manda Stripe, con el secreto de prueba.
class AvisoDePagoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'whsec_prueba';

    private Usuario $alumno;

    private Tienda $tienda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(JerarquiaInstitucionalSeeder::class);
        config(['services.stripe.webhook_secret' => self::SECRETO]);

        $this->alumno = Usuario::create([
            'nombre' => 'Ana',
            'apellido' => 'López',
            'correo_institucional' => 'ana@info.uas.edu.mx',
            'id_facultad' => Facultades::firstOrFail()->id,
            'hash_password' => Hash::make('secreta123'),
            'telefono' => '6671234567',
        ]);
        $this->alumno->asignarRol(Rol::USUARIO);

        $this->tienda = Tienda::create([
            'nombre' => 'Cafetería',
            'id_facultad' => $this->alumno->id_facultad,
            'cupo_bajo_demanda' => 3,
            'umbral_espera_min' => 15,
        ]);
    }

    public function test_cobro_confirmado_marca_el_pago_y_el_pedido_como_pagados(): void
    {
        [$pedido, $pago] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');

        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 8500, 'ch_1'))->assertOk();

        $this->assertSame(EstadoPedido::Pagado, $pedido->fresh()->estado);
        $this->assertSame(EstadoPago::Pagado, $pago->fresh()->estado);
        $this->assertSame('ch_1', $pago->fresh()->id_charge);
    }

    // "Voy saliendo": se paga pero no entra a cocina hasta que el alumno avisa (§7.3)
    public function test_en_voy_saliendo_el_pedido_queda_en_espera(): void
    {
        [$pedido] = $this->pedidoConPago(Pedido::RETIRO_VOY_SALIENDO, 'pi_1');

        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 8500))->assertOk();

        $this->assertSame(EstadoPedido::EnEspera, $pedido->fresh()->estado);
    }

    // HU-07 CA-02: si el cobro falla, el pedido no se confirma
    public function test_cobro_fallido_marca_el_pago_fallido_y_el_pedido_no_se_confirma(): void
    {
        [$pedido, $pago] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');

        $this->avisar('payment_intent.payment_failed', $this->intento('pi_1', 0))->assertOk();

        $this->assertSame(EstadoPedido::PendientePago, $pedido->fresh()->estado);
        $this->assertSame(EstadoPago::Fallido, $pago->fresh()->estado);
    }

    // Rechazaron la primera tarjeta y el alumno pagó con otra sobre el mismo cobro
    public function test_si_despues_de_fallar_el_cobro_sale_bien_el_pedido_se_paga(): void
    {
        [$pedido, $pago] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');

        $this->avisar('payment_intent.payment_failed', $this->intento('pi_1', 0))->assertOk();
        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 8500))->assertOk();

        $this->assertSame(EstadoPedido::Pagado, $pedido->fresh()->estado);
        $this->assertSame(EstadoPago::Pagado, $pago->fresh()->estado);
    }

    // HU-11 CA-02: un aviso de pago repetido (aunque llegue con otro id de evento) no cambia nada otra vez
    public function test_un_segundo_aviso_de_pago_no_mueve_el_pedido_otra_vez(): void
    {
        [$pedido] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');
        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 8500))->assertOk();

        // La tienda ya lo aceptó cuando llega otro aviso del mismo cobro
        $pedido->fresh()->forceFill(['estado' => EstadoPedido::Aceptado])->save();
        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 8500))->assertOk();

        $this->assertSame(EstadoPedido::Aceptado, $pedido->fresh()->estado);
    }

    // Si Stripe dice que cobró otra cantidad, no se da por pagado
    public function test_si_el_monto_cobrado_no_coincide_no_se_marca_pagado(): void
    {
        [$pedido, $pago] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');

        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 100))->assertOk();

        $this->assertSame(EstadoPedido::PendientePago, $pedido->fresh()->estado);
        $this->assertSame(EstadoPago::Pendiente, $pago->fresh()->estado);
    }

    // Un cobro que no está en pagos (de otra integración, o de prueba desde el dashboard)
    public function test_un_cobro_que_no_es_nuestro_responde_200_y_no_toca_nada(): void
    {
        [$pedido] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');

        $this->avisar('payment_intent.succeeded', $this->intento('pi_otro', 8500))->assertOk();

        $this->assertSame(EstadoPedido::PendientePago, $pedido->fresh()->estado);
    }

    // El cobro llega tarde, cuando el pedido ya se canceló: el pago se registra, el pedido no revive
    public function test_si_el_pedido_ya_se_cancelo_el_pago_se_registra_pero_el_pedido_no_cambia(): void
    {
        [$pedido, $pago] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');
        $pedido->forceFill(['estado' => EstadoPedido::CanceladoPorUsuario])->save();

        $this->avisar('payment_intent.succeeded', $this->intento('pi_1', 8500))->assertOk();

        $this->assertSame(EstadoPedido::CanceladoPorUsuario, $pedido->fresh()->estado);
        $this->assertSame(EstadoPago::Pagado, $pago->fresh()->estado);
    }

    public function test_marcar_pagado_no_toca_un_pedido_que_ya_no_esta_pendiente(): void
    {
        [$pedido] = $this->pedidoConPago(Pedido::RETIRO_FRANJA, 'pi_1');
        $pedido->forceFill(['estado' => EstadoPedido::EnPreparacion])->save();

        $resultado = app(PedidoService::class)->marcarPagado($pedido);

        $this->assertSame(EstadoPedido::EnPreparacion, $resultado->estado);
    }

    // --- Ayudas ---

    // Un pedido de $85.00 esperando el pago, con su renglón "pendiente" en pagos
    private function pedidoConPago(string $modo, string $idIntento): array
    {
        $pedido = Pedido::create([
            'codigo_pedido' => 'TA-'.Str::upper(Str::random(6)),
            'clave_idempotencia' => (string) Str::uuid(),
            'id_usuario' => $this->alumno->id,
            'id_tienda' => $this->tienda->id,
            'estado' => EstadoPedido::PendientePago,
            'modo_retiro' => $modo,
            'total' => '85.00',
            'pin_retiro' => '0420',
        ]);
        $pago = Pago::create([
            'id_pedido' => $pedido->id,
            'id_payment_intent' => $idIntento,
            'monto' => '85.00',
            'comision_plataforma' => '4.25',
            'estado' => EstadoPago::Pendiente,
        ]);

        return [$pedido, $pago];
    }

    private function intento(string $id, int $centavosCobrados, ?string $idCargo = null): array
    {
        return array_filter([
            'id' => $id,
            'object' => 'payment_intent',
            'amount' => 8500,
            'amount_received' => $centavosCobrados,
            'latest_charge' => $idCargo,
        ], fn ($valor) => $valor !== null);
    }

    private function avisar(string $tipo, array $intento)
    {
        $cuerpo = json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $tipo,
            'account' => 'acct_123',
            'data' => ['object' => $intento],
        ]);
        $t = time();
        $firma = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$cuerpo}", self::SECRETO);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $firma,
            'CONTENT_TYPE' => 'application/json',
        ], $cuerpo);
    }
}
