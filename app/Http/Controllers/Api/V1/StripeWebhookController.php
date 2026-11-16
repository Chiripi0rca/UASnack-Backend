<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PagoService;
use App\Services\PedidoService;
use App\Services\Stripe\FirmasStripe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Avisos que manda Stripe a POST /webhooks/stripe. Una sola ruta para todos:
// la cuenta de la tienda y los pagos llegan como eventos de cuentas conectadas.
//   #146 verifica la firma (Stripe-Signature) y atiende account.updated.
//   #62 atiende payment_intent.succeeded y payment_intent.payment_failed.
//   #77 no procesa dos veces el mismo event.id.
// Es público (sin sesión): lo que lo protege es la firma. Responde 200 rápido, porque Stripe reintenta si no.
class StripeWebhookController extends Controller
{
    public function __construct(
        private PedidoService $pedidos,
        private PagoService $pagos,
        private FirmasStripe $firmas,
    ) {
    }

    public function recibir(Request $request): Response
    {
        $evento = $this->firmas->verificar($request->getContent(), $request->header('Stripe-Signature'));
        $this->pagos->manejarWebhook($evento);

        return response()->noContent(200);
    }
}
