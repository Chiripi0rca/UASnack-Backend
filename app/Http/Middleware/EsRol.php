<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ESQUELETO (Sprint 02). Lo completa la tarea #162.
//
// Deja pasar solo a los roles indicados. Se usa con el alias "rol" (registrado en bootstrap/app.php),
// siempre después de auth:sanctum. Ejemplos:
//   ->middleware('rol:administrador')
//   ->middleware('rol:encargado_tienda')
//
// Por ahora no revisa nada: deja pasar a todos. Las rutas de /tienda y /admin ya se pueden escribir,
// pero no deben llegar a main hasta que la tarea 162 lo complete.
class EsRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Pendiente la tarea 162: si el rol del usuario no está en $roles, responder 403 con el formato del proyecto.

        return $next($request);
    }
}
