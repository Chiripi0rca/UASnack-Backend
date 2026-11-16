<?php

namespace App\Services\Stripe;

use App\Exceptions\ErrorNegocio;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

// Revisa que un aviso realmente lo mandó Stripe (#146). Está aparte para poder simularlo en pruebas.
// Se necesita el cuerpo tal cual llegó (sin re-codificar), si no la firma no coincide.
class FirmasStripe
{
    // Regresa el evento como arreglo (id, type, account, data...). Si la firma no sirve, 400.
    public function verificar(string $cuerpo, ?string $firma): array
    {
        $secreto = config('services.stripe.webhook_secret');
        if (! $secreto) {
            throw new ErrorNegocio('STRIPE_SIN_CONFIGURAR', 'Los pagos con Stripe no están configurados.', 503);
        }

        try {
            return Webhook::constructEvent($cuerpo, (string) $firma, $secreto)->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw new ErrorNegocio('FIRMA_INVALIDA', 'La firma del aviso no es válida.', 400);
        }
    }
}
