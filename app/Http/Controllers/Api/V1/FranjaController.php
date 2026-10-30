<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FranjasRequest;
use App\Services\CapacidadService;
use App\Services\CatalogoService;
use Illuminate\Http\JsonResponse;

// Franjas de retiro de una tienda, para la pantalla de Elección de retiro (#54 PWA, #55 Android).
class FranjaController extends Controller
{
    public function __construct(
        private CapacidadService $capacidad,
        private CatalogoService $catalogo,
    ) {
    }

    // GET /tiendas/{id}/franjas?minutos=18&limite=8 (#53)
    // Cada franja es una hora para RECOGER el pedido (se cocina en las franjas de antes).
    // meta.cabe_hoy = false: el pedido no cabe en ninguna hora del día. Con minutos_maximos_hoy la app
    // le dice cuánto sí cabe ("tu pedido necesita 45 min y hoy solo caben hasta 30"). Si
    // minutos_maximos_hoy es 0, la tienda ya no tiene horas para hoy, sin importar el tamaño del pedido.
    public function franjas(FranjasRequest $request, int $id): JsonResponse
    {
        $tienda = $this->catalogo->tiendaActiva($id); // 404 si no existe o está de baja
        $ahora = now(CapacidadService::ZONA_HORARIA);
        $minutos = $request->integer('minutos');

        $franjas = $this->capacidad->franjasDisponibles(
            $tienda,
            $ahora,
            $minutos,
            $request->integer('limite', FranjasRequest::LIMITE_MAXIMO),
        );
        $maximo = $this->capacidad->minutosMaximosHoy($tienda, $ahora);

        return response()->json([
            'datos' => $franjas->values(),
            'meta' => [
                'total' => $franjas->count(),
                'cabe_hoy' => $franjas->contains(fn (array $franja) => $franja['disponible']),
                'minutos_maximos_hoy' => $maximo,
            ],
        ]);
    }
}
