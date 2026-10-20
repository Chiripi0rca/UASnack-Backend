<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TiendaResource;
use App\Models\Tienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TiendaController extends Controller
{
    /**
     * GET /api/v1/tiendas
     *
     * Obtiene las tiendas activas, ordenadas primero por
     * la facultad del usuario y después por nombre.
     */
    public function obtenerTiendas(Request $request): JsonResponse
    {
        $tiendas = Tienda::where('activa', true)
            ->orderByRaw('id_facultad = ? DESC', [
                $request->user()->id_facultad,
            ])
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'datos' => TiendaResource::collection($tiendas)
                ->resolve($request),
            'meta' => [
                'total' => $tiendas->count(),
            ],
        ]);
    }

    // PATCH /admin/tiendas/{id}/encargado se movió a TiendaAdminController (#104),
    // con el resto de los endpoints del administrador.
}