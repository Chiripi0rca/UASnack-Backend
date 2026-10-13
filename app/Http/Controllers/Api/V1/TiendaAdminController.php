<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearTiendaRequest;
use App\Http\Requests\ActualizarTiendaRequest;
use App\Http\Resources\TiendaAdminResource;
use App\Services\TiendaAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Endpoints de tiendas para el administrador de una facultad.
class TiendaAdminController extends Controller
{

    public function __construct(private TiendaAdminService $tiendaService)
    {
    }


    // GET /admin/tiendas
    public function obtenerTiendas(Request $request): JsonResponse
    {
        //$request->user() es el administrador dueño del token
        $tiendas = $this->tiendaService->tiendasAdmin($request->user());

        return response()->json([
            'datos' => TiendaAdminResource::collection($tiendas)->resolve($request),
            'meta' => ['total' => $tiendas->count()],
        ]);
    }

    // POST /admin/tiendas
    public function crearTienda(CrearTiendaRequest $request): JsonResponse
    {
        // validated() regresa solo los campos que pasaron las reglas (nunca all())
        $tienda = $this->tiendaService->crearTienda($request->user(), $request->validated());

        // Un solo recurso, envuelto en 'datos'. 201 = "creado".
        return response()->json([
            'datos' => (new TiendaAdminResource($tienda))->resolve($request),
        ], 201);
    }

    //PATCH /admin/tiendas
    public function actualizarTienda(ActualizarTiendaRequest $request, int $id): JsonResponse
    {
        $tienda = $this->tiendaService->actualizarTienda(
            $request->user(),
            $id,
            $request->validated()
        );

        return response()->json([
            'datos' => (new TiendaAdminResource($tienda))->resolve($request),
        ]);
    }
}
