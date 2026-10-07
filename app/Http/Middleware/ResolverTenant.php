<?php

namespace App\Http\Middleware;

use App\Enums\RolUsuario;
use App\Exceptions\ErrorNegocio;
use App\Models\Tienda;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Resuelve a qué facultad pertenece el usuario y, si es encargado, cuál es su tienda (#162).
// Lo deja en App\Support\Tenant para que los controladores y los global scopes (#163)
// no tengan que volver a buscarlo.
//
// Se usa con el alias "tenant", siempre después de auth:sanctum (necesita saber quién es el usuario).
// La tarea #163 le da prioridad sobre SubstituteBindings (en bootstrap/app.php): así la tienda ya
// está resuelta cuando Laravel busca el {producto} de la ruta, y el global scope puede filtrarlo.
class ResolverTenant
{
    public function __construct(private Tenant $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        $this->tenant->rol = $usuario->rol;

        // Para el alumno es solo la preferencia de orden (sus tiendas primero), nunca un filtro.
        $this->tenant->idFacultad = $usuario->id_facultad;

        if ($usuario->rol === RolUsuario::Encargado_Tienda) {
            $idTienda = Tienda::where('id_dueno', $usuario->id)->value('id');

            // Un encargado sin tienda no tiene nada que operar: se corta aquí
            // en lugar de dejar que cada controlador truene con un id_tienda nulo.
            if (! $idTienda) {
                throw new ErrorNegocio(
                    'SIN_TIENDA_ASIGNADA',
                    'Tu cuenta no tiene una tienda asignada. Pide al administrador que te asigne una.',
                    403,
                );
            }

            $this->tenant->idTienda = $idTienda;
        }

        return $next($request);
    }
}
