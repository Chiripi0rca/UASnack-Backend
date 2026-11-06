<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PedidoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ESQUELETO (Sprint 04). Avisos que manda Stripe a POST /webhooks/stripe. Una sola ruta para todos:
// la cuenta de la tienda y los pagos llegan como eventos de cuentas conectadas.
//   #146 (José Ángel): verifica la firma (Stripe-Signature) y atiende account.updated.
//   #62 (Ricardo): atiende payment_intent.succeeded y payment_intent.payment_failed.
//   #77 (José Ángel): no procesa dos veces el mismo event.id.
// Es público (sin sesión): lo que lo protege es la firma. Responde 200 rápido, porque Stripe reintenta si no.
class StripeWebhookController extends Controller
{
    public function __construct(private PedidoService $pedidos)
    {
    }

    public function recibir(Request $request): Response
    {
        return response()->noContent(200);
    }
}
