<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Http\Controllers\Controller;
use App\Services\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// ESQUELETO (Sprint 04). Pedidos del alumno. Lo completa #148 (José Ángel).
class PedidoController extends Controller
{
    public function __construct(private PedidoService $pedidos)
    {
    }

    // POST /pedidos con el header Idempotency-Key. Responde 201, o 200 si la clave se repite (#74).
    //   Envía:  { id_tienda, modo_retiro, franja, lineas: [{ id_producto, cantidad, extras, quitables }] }
    //   Regresa: { datos: { codigo_pedido, estado, total, franja_inicio, franja_fin,
    //                       pago: { client_secret, stripe_account } } }
    public function crear(Request $request): JsonResponse
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'Crear el pedido todavía no está disponible.', 501);
    }
}
