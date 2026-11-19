<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ErrorNegocio;
use App\Http\Controllers\Controller;
use App\Models\Tienda;
use App\Services\PagoService;
use App\Support\Tenant;
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

    // #188: GET /pagos/metodos
    //   Responde { datos: [{ id, marca, ultimos4, vence }] }
    // Recibe la petición HTTP ($request) y regresa una respuesta en JSON.
    public function metodos(Request $request): JsonResponse
    {
        // response()->json() arma una respuesta HTTP 200 y convierte el arreglo a JSON.
        return response()->json([
            // $request->user() es el alumno dueño del token (lo identificó auth:sanctum),
            // así que solo se consultan SUS tarjetas, nunca las de otro alumno.
            // metodosDe ya regresa cada tarjeta con la forma del contrato; aquí solo se mete en "datos".
            'datos' => $this->pagos->metodosDe($request->user()),
        ]);
    }

    // #65: POST /pagos/metodos → { datos: { client_secret } }
    // La app usa ese client_secret para que Stripe capture y guarde la tarjeta.
    public function agregarMetodo(Request $request): JsonResponse
    {
        return response()->json([
            'datos' => ['client_secret' => $this->pagos->crearSetupIntent($request->user())],
        ]);
    }

    // #65: DELETE /pagos/metodos/{id} → 204 (sin cuerpo). Si la tarjeta no es suya, 404.
    public function eliminarMetodo(Request $request, string $id): Response
    {
        $this->pagos->eliminarMetodo($request->user(), $id);

        return response()->noContent();
    }

    // #109: POST /tienda/pagos/onboarding
    //   { datos: { url } } → el panel abre esa URL (formulario de Stripe)
    // La tienda sale del token (middleware tenant, #162): el cliente nunca manda id_tienda.
    public function onboarding(Tenant $tenant): JsonResponse
    {
        $url = $this->pagos->generarLinkOnboarding(Tienda::findOrFail($tenant->idTienda));

        return response()->json(['datos' => ['url' => $url]]);
    }

    // #109: GET /tienda/pagos/estado
    //   { datos: { estado } } con "verificado", "pendiente" o "rechazado"
    public function estadoCuenta(Tenant $tenant): JsonResponse
    {
        $estado = $this->pagos->estadoCuenta(Tienda::findOrFail($tenant->idTienda));

        return response()->json(['datos' => ['estado' => $estado]]);
    }
}
