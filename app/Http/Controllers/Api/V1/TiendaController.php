<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Tienda;

use App\Http\Controllers\Controller;
use App\Http\Resources\TiendaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TiendaController extends Controller
{
    // GET /tiendas
    public function obtenerTiendas(Request $request): JsonResponse
    {
        // Tiendas activas de todas las facultades.
        // Primero las de la facultad del alumno; dentro de cada grupo, por nombre.
        $tiendas = Tienda::where('activa', true)
            ->orderByRaw('id_facultad = ? DESC', [$request->user()->id_facultad])
            ->orderBy('nombre')
            ->get();

        // respuesta
        return response()->json([
            'datos' => TiendaResource::collection($tiendas)->resolve($request),
            'meta' => ['total' => $tiendas->count()],
        ]);
    }
}
