<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarCapacidadRequest;
use App\Http\Resources\CapacidadFranjaResource;
use App\Services\CapacidadPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Capacidad de cocina por franja del encargado, para la vista "Franjas y capacidad" del panel (#107).
class CapacidadController extends Controller
{
    public function __construct(private CapacidadPanelService $capacidadService)
    {
    }

    // GET /tienda/capacidad (#106)
    public function obtenerCapacidad(Request $request): JsonResponse
    {
        return $this->respuesta($request, $this->capacidadService->capacidadDe($request->user()));
    }

    // PUT /tienda/capacidad (#106)
    // Recibe personas por franja; el servicio las convierte a minutos de cocina y las guarda.
    public function actualizarCapacidad(ActualizarCapacidadRequest $request): JsonResponse
    {
        $capacidad = $this->capacidadService->actualizarCapacidad(
            $request->user(),
            $request->validated()['franjas']
        );

        return $this->respuesta($request, $capacidad);
    }

    // { datos: [{ dia_semana, hora_inicio, hora_fin, minutos_cocina, personas }],
    //   meta: { total, duracion_franja_min } }
    private function respuesta(Request $request, array $capacidad): JsonResponse
    {
        $duracion = $capacidad['duracion_franja_min'];

        return response()->json([
            'datos' => $capacidad['franjas']
                ->map(fn ($franja) => (new CapacidadFranjaResource($franja, $duracion))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $capacidad['franjas']->count(),
                'duracion_franja_min' => $duracion,
            ],
        ]);
    }
}
