```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Exceptions\ErrorNegocio;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Auth\AuthenticationException;

use App\Http\Middleware\CorreoVerificado;
use App\Http\Middleware\EsRol;
use App\Http\Middleware\ResolverTenant;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias para usarlo en las rutas como ->middleware('correo.verificado').
        // Siempre después de auth:sanctum, porque necesita saber quién es el usuario.
        // "rol" y "tenant" son esqueletos del Sprint 02: los completa la tarea 162.
        $middleware->alias([
            'correo.verificado' => CorreoVerificado::class,
            'rol' => EsRol::class,
            'tenant' => ResolverTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 422: código para cuando llegan datos inválidos
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'codigo' => 'VALIDACION',
                        'mensaje' => 'Revisa los datos marcados.',
                        'campos' => $e->errors(),
                    ],
                ], 422, [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ]);
            }
        });

        // 429: demasiados intentos (throttle). Antes salía en inglés y con el trace completo.
        // $e->getHeaders() reenvía Retry-After y X-RateLimit-*, que la web usa para el contador.
        // El mensaje no lleva los segundos porque la web ya agrega "Intenta de nuevo en 0:43".
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'codigo' => 'DEMASIADOS_INTENTOS',
                        'mensaje' => 'Demasiados intentos.',
                    ],
                ], 429, array_merge(
                    $e->getHeaders(),
                    ['Content-Type' => 'application/json; charset=UTF-8']
                ));
            }
        });

        // 401 de auth:sanctum: el token venció, fue revocado o no se mandó.
        // Es distinto del 401 del login (CREDENCIALES_INVALIDAS): el cliente los distingue
        // por el campo codigo. Aplica a toda ruta protegida, no solo al logout.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'codigo' => 'NO_AUTENTICADO',
                        'mensaje' => 'Tu sesión expiró. Inicia sesión de nuevo.',
                    ],
                ], 401, [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ]);
            }
        });

        // Errores de negocio que lanzan los servicios (CODIGO_INVALIDO, CREDENCIALES_INVALIDAS, etc.).
        // El servicio decide código, mensaje, status y headers; aquí solo se le da la forma del proyecto.
        // $e->headers sirve para mandar Retry-After en REENVIO_MUY_PRONTO y que arranque el contador de la web.
        $exceptions->render(function (ErrorNegocio $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'codigo' => $e->codigo,
                        'mensaje' => $e->getMessage(),
                    ],
                ], $e->status, array_merge(
                    $e->headers,
                    ['Content-Type' => 'application/json; charset=UTF-8']
                ));
            }
        });
    })->create();
