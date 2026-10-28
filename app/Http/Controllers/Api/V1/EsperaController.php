<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EsperaRequest;
use App\Services\CapacidadService;
use App\Services\CatalogoService;
use Illuminate\Http\JsonResponse;

// Tiempo de espera del modo "voy saliendo", para la pantalla de Elección de retiro (#54 PWA, #55 Android).
class EsperaController extends Controller
{
    public function __construct(
        private CapacidadService $capacidad,
        private CatalogoService $catalogo,
    ) {
    }

    // GET /tiendas/{id}/espera?minutos=18 (#57)
    // Siempre regresa un rango (min y max), nunca un solo número (§7.4).
    public function espera(EsperaRequest $request, int $id): JsonResponse
    {
        $espera = $this->capacidad->estimarEspera(
            $this->catalogo->tiendaActiva($id), // 404 si no existe o está de baja
            $request->integer('minutos'),
        );

        return response()->json(['datos' => $espera]);
    }
}
