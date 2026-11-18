<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearPedidoRequest;
use App\Http\Resources\PedidoResource;
use App\Services\PedidoService;
use Illuminate\Http\JsonResponse;

// Pedidos del alumno.
class PedidoController extends Controller
{
    public function __construct(private PedidoService $pedidos)
    {
    }

    // POST /pedidos con el header Idempotency-Key. Responde 201, o 200 si la clave se repite (#74).
    //   Envía:  { id_tienda, modo_retiro, franja, lineas: [{ id_producto, cantidad, extras, quitables }] }
    //   Regresa: { datos: { codigo_pedido, estado, total, franja_inicio, franja_fin,
    //                       pago: { client_secret, stripe_account } } }
    public function crear(CrearPedidoRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $pedido = $this->pedidos->crear(
            $request->user(),
            $datos['id_tienda'],
            $datos['lineas'],
            $datos['modo_retiro'],
            $request->franja(),
            $request->attributes->get('clave_idempotencia'),
        );

        return response()->json(['datos' => new PedidoResource($pedido)], $pedido->wasRecentlyCreated ? 201 : 200);
    }
}
