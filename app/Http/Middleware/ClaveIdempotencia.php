<?php

namespace App\Http\Middleware;

use App\Exceptions\ErrorNegocio;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// #74 (HU-11): toda creación de pedido trae el header Idempotency-Key con un UUID. La app lo genera UNA vez
// por intento y lo repite en cada reintento (shared/api/idempotencia.js en la PWA, #183 en Android):
// así el servidor reconoce que es el mismo pedido y no lo crea ni lo cobra dos veces.
//
// Uso en la ruta: ->middleware('idempotencia'). Si la clave falta o no es un UUID, corta con 422.
// Si es válida, la deja en minúsculas en $request->attributes como 'clave_idempotencia', para que el
// controlador la lea de ahí y no del header.
class ClaveIdempotencia
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $clave = trim((string) $request->header(self::HEADER));

        if (! Str::isUuid($clave)) {
            throw new ErrorNegocio(
                'CLAVE_IDEMPOTENCIA_INVALIDA',
                'No pudimos registrar tu pedido. Actualiza la app e inténtalo de nuevo.',
                422,
            );
        }

        // Mayúsculas y minúsculas son el mismo UUID: se guarda siempre igual para que la búsqueda la encuentre
        $request->attributes->set('clave_idempotencia', strtolower($clave));

        return $next($request);
    }
}
