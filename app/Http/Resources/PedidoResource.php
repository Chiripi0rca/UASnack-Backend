<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// El pedido que ve el alumno al crearlo (#148). Sin PIN: ese solo sale con el pedido en LISTO.
// "pago" solo va cuando el pedido espera su cobro: es lo que la app necesita para confirmarlo con Stripe.
class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pago = $this->resource->relationLoaded('pago') ? $this->resource->getRelation('pago') : null;

        return [
            'codigo_pedido' => $this->codigo_pedido,
            'estado' => $this->estado->value,
            'total' => $this->total,
            // la base guarda la hora de pared de la tienda: se muestra como "11:30"
            'franja_inicio' => $this->franja_inicio?->format('H:i'),
            'franja_fin' => $this->franja_fin?->format('H:i'),
            'pago' => $pago ? [
                'client_secret' => $pago['client_secret'],
                'stripe_account' => $pago['stripe_account'],
            ] : null,
        ];
    }
}
