<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ESQUELETO (Sprint 02). Lo completa la tarea 162.
//
// Resuelve la facultad del usuario y, si es encargado de tienda, el id de su tienda,
// para que los controladores y los global scopes (#163) filtren por ellos.
// Se usa con el alias "tenant" (registrado en bootstrap/app.php), siempre después de auth:sanctum.
//
// Por ahora no hace nada: deja pasar la petición.
class ResolverTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pendiente la tarea 162: guardar id_facultad y, si el rol es encargado_tienda, el id de su tienda.

        return $next($request);
    }
}
