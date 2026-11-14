<?php

namespace App\Services\Stripe;

use App\Exceptions\ErrorNegocio;
use App\Models\Tienda;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// Lo único que habla con la API de Stripe para las cuentas conectadas de las tiendas (#109).
// Está aparte de PagoService para que las pruebas lo puedan simular sin llamar a Stripe.
// Las llaves salen de config/services.php (stripe.secret), que las lee del .env (#176).
//
// Usa Accounts v2: Stripe ya no deja crear cuentas nuevas con la API anterior cuando la plataforma
// responde por las pérdidas. Lo que Stripe acepta para México (probado en modo de prueba, 14-nov-2026):
//   dashboard "full": la tienda tiene su propio panel completo de Stripe
//   fees_collector "stripe": Stripe le cobra sus comisiones directo a la tienda
//   losses_collector "stripe": Stripe responde por fraudes y contracargos, no UASnack
// Encaja con el cobro directo (§9): el cargo nace en la cuenta de la tienda.
class CuentasStripe
{
    // Configuración que se le pide a cada cuenta: "merchant" = puede cobrar con tarjeta
    private const CONFIGURACION = 'merchant';

    // Crea la cuenta conectada de la tienda y regresa su id (acct_…).
    // Los datos sensibles (RFC, identificación, CLABE) los captura Stripe en su formulario de alta;
    // UASnack nunca los recibe ni los guarda.
    // La idempotency_key evita crear dos cuentas si el encargado toca el botón dos veces.
    public function crearCuenta(Tienda $tienda): string
    {
        $datos = [
            'display_name' => $tienda->nombre,
            'dashboard' => 'full',
            'identity' => ['country' => 'mx'],
            'defaults' => [
                'currency' => 'mxn',
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'configuration' => [
                self::CONFIGURACION => ['capabilities' => ['card_payments' => ['requested' => true]]],
            ],
            'metadata' => ['id_tienda' => (string) $tienda->id],
        ];
        if ($tienda->dueno?->correo_institucional) {
            $datos['contact_email'] = $tienda->dueno->correo_institucional;
        }

        $cuenta = $this->llamar(fn (StripeClient $stripe) => $stripe->v2->core->accounts->create(
            $datos,
            ['idempotency_key' => "uasnack-tienda-{$tienda->id}-cuenta"],
        ));

        return $cuenta->id;
    }

    // Enlace temporal de Stripe para dar de alta (o terminar de dar de alta) la cuenta.
    // $regreso: a dónde vuelve el encargado al terminar. $vencido: a dónde va si el enlace ya caducó.
    public function crearEnlaceDeAlta(string $idCuenta, string $regreso, string $vencido): string
    {
        $enlace = $this->llamar(fn (StripeClient $stripe) => $stripe->v2->core->accountLinks->create([
            'account' => $idCuenta,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => ['return_url' => $regreso, 'refresh_url' => $vencido],
            ],
        ]));

        return $enlace->url;
    }

    // Lo que importa de la cuenta para saber su estado:
    //   puede_cobrar → la capacidad de cobrar con tarjeta ya está activa
    //   rechazada    → Stripe no la aceptó (la cerró, o la tarjeta quedó rechazada)
    public function consultarCuenta(string $idCuenta): array
    {
        $cuenta = $this->llamar(fn (StripeClient $stripe) => $stripe->v2->core->accounts->retrieve(
            $idCuenta,
            ['include' => ['configuration.'.self::CONFIGURACION]],
        ));

        $tarjeta = $cuenta->configuration?->merchant?->capabilities?->card_payments;
        $motivos = array_map(fn ($detalle) => (string) $detalle->code, (array) ($tarjeta?->status_details ?? []));

        return [
            'puede_cobrar' => $tarjeta?->status === 'active',
            'rechazada' => (bool) $cuenta->closed
                || collect($motivos)->contains(fn (string $codigo) => str_contains($codigo, 'rejected')),
        ];
    }

    // Cualquier error de Stripe (sin red, llave mala, datos rechazados) se responde con el formato de
    // siempre, sin mostrarle al encargado el mensaje técnico de Stripe. El detalle queda en el log.
    private function llamar(callable $llamada): mixed
    {
        $llave = config('services.stripe.secret');
        if (! $llave) {
            throw new ErrorNegocio(
                'STRIPE_SIN_CONFIGURAR',
                'Los pagos todavía no están configurados en el servidor.',
                503,
            );
        }

        try {
            return $llamada(new StripeClient($llave));
        } catch (ApiErrorException $error) {
            report($error);

            throw new ErrorNegocio(
                'STRIPE_NO_DISPONIBLE',
                'No pudimos conectar con Stripe. Intenta de nuevo en un momento.',
                502,
            );
        }
    }
}
