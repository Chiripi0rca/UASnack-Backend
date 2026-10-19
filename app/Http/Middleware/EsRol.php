<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Deja pasar solo a los roles indicados (#162). Se usa con el alias "rol",
// siempre después de auth:sanctum. Ejemplos:
//   ->middleware('rol:administrador')
//   ->middleware('rol:encargado_tienda')
//   ->middleware('rol:encargado_tienda,administrador')   // cualquiera de los dos
class EsRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Se compara la clave del rol (tabla roles), la misma que va en la ruta.
        if (! in_array($request->user()->claveRol(), $roles, true)) {
            throw new ErrorNegocio(
                'SIN_PERMISO',
                'No tienes permiso para entrar a esta sección.',
                403,
            );
        }

        return $next($request);
    }
}
