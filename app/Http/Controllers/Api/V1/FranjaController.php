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
    public function franjas(FranjasRequest $request, int $id): JsonResponse
    {
        $franjas = $this->capacidad->franjasDisponibles(
            $this->catalogo->tiendaActiva($id), // 404 si no existe o está de baja
            now(CapacidadService::ZONA_HORARIA),
            $request->integer('minutos'),
            $request->integer('limite', FranjasRequest::LIMITE_MAXIMO),
        );

        return response()->json([
            'datos' => $franjas->values(),
            'meta' => ['total' => $franjas->count()],
        ]);
    }
}
