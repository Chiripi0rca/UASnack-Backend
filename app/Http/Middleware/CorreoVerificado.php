<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Bloquea las rutas de pedidos a quien no ha verificado su correo institucional (HU-01, CA-03).
// Va DESPUÉS de auth:sanctum: aquí ya se sabe quién es el usuario, solo falta revisar si verificó.
// El login ya no da token sin verificar; este middleware es la segunda barrera, por si algún día
// llega a existir un token de alguien sin verificar (seeder, SSO, cambio de correo, etc.).
class CorreoVerificado
{
    public function handle(Request $request, Closure $next): Response
    {
        // Si correo_verificado_en es null, nunca verificó: se corta aquí y el controlador ni se ejecuta.
        // Se lanza ErrorNegocio para que bootstrap/app.php responda con el formato { error: { codigo, mensaje } }.
        if (! $request->user()?->correo_verificado_en) {
            throw new ErrorNegocio(
                'CORREO_NO_VERIFICADO',
                'Verifica tu correo institucional para poder hacer pedidos.',
                403,
            );
        }

        // Ya verificó: la petición sigue su camino hacia el controlador.
        return $next($request);
    }
}