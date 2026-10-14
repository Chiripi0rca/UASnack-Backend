<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AsignarEncargadoRequest;
use App\Http\Resources\TiendaResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Tienda;
use App\Services\AsignarEncargadoService;
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

    /**
     * PATCH /api/v1/admin/tiendas/{id}/encargado
     *
     * Asigna o cambia el encargado de una tienda.
     * La autorización se controla mediante los middlewares
     * registrados en routes/api.php.
     */
    public function asignarEncargado(
        AsignarEncargadoRequest $request,
        int $id,
        AsignarEncargadoService $servicio
    ): JsonResponse {
        $datos = $request->validated();

        $tienda = $servicio->asignar(
            $id,
            $datos['correo_institucional']
        );

        return response()->json([
            'mensaje' => 'Encargado asignado correctamente.',
            'datos' => [
                'id_tienda' => $tienda->id,
                'nombre_tienda' => $tienda->nombre,
                'encargado' => (new UsuarioResource(
                    $tienda->dueno
                ))->resolve($request),
            ],
        ], 200);
    }
}
