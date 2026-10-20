<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearTiendaRequest;
use App\Http\Requests\ActualizarTiendaRequest;
use App\Http\Requests\AsignarEncargadoRequest;
use App\Http\Resources\TiendaAdminResource;
use App\Services\AsignarEncargadoService;
use App\Services\TiendaAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Endpoints de tiendas para el administrador de una facultad.
class TiendaAdminController extends Controller
{

    public function __construct(
        private TiendaAdminService $tiendaService,
        private AsignarEncargadoService $encargadoService,
    ) {
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

    // PATCH /admin/tiendas/{id}/encargado (#104, HU-31)
    // Responde la tienda con la misma forma que GET /admin/tiendas, para que el panel
    // reemplace el renglón sin volver a pedir la lista.
    public function asignarEncargado(AsignarEncargadoRequest $request, int $id): JsonResponse
    {
        $tienda = $this->encargadoService->asignar(
            $request->user(),
            $id,
            $request->validated()['correo_institucional']
        );

        return response()->json([
            'mensaje' => 'Encargado asignado correctamente.',
            'datos' => (new TiendaAdminResource($tienda))->resolve($request),
        ]);
    }

    // GET /admin/encargados (#104): encargados de la facultad del admin, con su tienda.
    public function obtenerEncargados(Request $request): JsonResponse
    {
        $encargados = $this->encargadoService->encargados($request->user());

        return response()->json([
            'datos' => $encargados->values(),
            'meta' => ['total' => $encargados->count()],
        ]);
    }

    // GET /admin/usuarios/buscar?correo=&id_tienda= (#104): a quién se va a asignar, antes de guardar.
    public function buscarUsuario(Request $request): JsonResponse
    {
        $request->merge(['correo' => strtolower(trim((string) $request->query('correo', '')))]);

        $datos = $request->validate([
            'correo' => ['required', 'email', 'max:255'],
            'id_tienda' => ['nullable', 'integer'],
        ], [
            'correo.required' => 'Escribe el correo del encargado.',
            'correo.email' => 'Escribe un correo institucional válido.',
        ]);

        return response()->json([
            'datos' => $this->encargadoService->buscarCandidato(
                $datos['correo'],
                isset($datos['id_tienda']) ? (int) $datos['id_tienda'] : null
            ),
        ]);
    }
}