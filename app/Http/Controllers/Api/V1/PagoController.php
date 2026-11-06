<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Http\Controllers\Controller;
use App\Services\PagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ESQUELETO (Sprint 04). Pagos del alumno y cuenta de Stripe de la tienda.
// Cada método lo completa su tarea y llama a PagoService. Las rutas están comentadas en routes/api.php.
class PagoController extends Controller
{
    public function __construct(private PagoService $pagos)
    {
    }

    // #69 (Alfredo): GET /pagos
    //   { datos: [{ id, codigo_pedido, monto, estado, creado_en }], meta: { total } }
    public function historial(Request $request): JsonResponse
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'El historial de pagos todavía no está disponible.', 501);
    }

    // #188 (Alfredo): GET /pagos/metodos
    //   { datos: [{ id, marca, ultimos4, vence }] }
    public function metodos(Request $request): JsonResponse
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'Las tarjetas guardadas todavía no están disponibles.', 501);
    }

    // #65 (Alfredo): POST /pagos/metodos. La app guarda la tarjeta con este client_secret.
    //   { datos: { client_secret } }
    public function agregarMetodo(Request $request): JsonResponse
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'Guardar tarjetas todavía no está disponible.', 501);
    }

    // #65 (Alfredo): DELETE /pagos/metodos/{id}. Responde 204.
    public function eliminarMetodo(Request $request, string $id): Response
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'Eliminar tarjetas todavía no está disponible.', 501);
    }

    // #109 (Ricardo): POST /tienda/pagos/onboarding
    //   { datos: { url } }
    public function onboarding(Request $request): JsonResponse
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'El alta de la cuenta todavía no está disponible.', 501);
    }

    // #109 (Ricardo): GET /tienda/pagos/estado
    //   { datos: { estado } } con "verificado", "pendiente" o "rechazado"
    public function estadoCuenta(Request $request): JsonResponse
    {
        throw new ErrorNegocio('NO_IMPLEMENTADO', 'El estado de la cuenta todavía no está disponible.', 501);
    }
}
