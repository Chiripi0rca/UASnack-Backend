<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ReenviarCodigoRequest;
use App\Http\Requests\RegistrarUsuarioRequest;
use App\Http\Requests\VerificarCorreoRequest;
use App\Http\Resources\UsuarioResource;
use App\Services\AuthService;
use App\Services\VerificacionCorreoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Solo recibe la petición, delega al servicio y responde.
// Los errores de negocio los lanza el servicio como ErrorNegocio y los responde bootstrap/app.php.
class AuthController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private VerificacionCorreoService $verificacion,
    ) {
    }

    public function registro(RegistrarUsuarioRequest $request): JsonResponse
    {
        $this->auth->registrar($request->validated());

        return response()->json([
            'mensaje' => 'Usuario registrado exitosamente. Se ha enviado un código de verificación al correo institucional.',
            'reenviar_en_segundos' => VerificacionCorreoService::SEGUNDOS_REENVIO,
        ], 201);
    }

    // Recibe correo y contraseña, y si son correctos devuelve un token de Sanctum.
    public function login(LoginRequest $request): JsonResponse
    {
        $sesion = $this->auth->iniciarSesion($request->correo_institucional, $request->password);

        return $this->respuestaSesion($sesion);
    }

    // Recibe correo y código; si el código es correcto verifica el correo y devuelve la sesión.
    public function verificar(VerificarCorreoRequest $request): JsonResponse
    {
        $sesion = $this->auth->verificarCorreo($request->correo_institucional, $request->codigo);

        return $this->respuestaSesion($sesion);
    }

    // Responde lo mismo exista o no el correo, salvo el 429 si aún no pasa el minuto.
    public function reenviarCodigo(ReenviarCodigoRequest $request): JsonResponse
    {
        $this->verificacion->reenviar($request->correo_institucional);

        return response()->json([
            'mensaje' => 'Si el correo está registrado y pendiente de verificación, se envió un nuevo código.',
            'reenviar_en_segundos' => VerificacionCorreoService::SEGUNDOS_REENVIO,
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->cerrarSesion($request->user());

        // 204 = todo bien, sin contenido que devolver.
        return response()->noContent();
    }

    private function respuestaSesion(array $sesion): JsonResponse
    {
        return response()->json([
            'token' => $sesion['token'],
            'usuario' => new UsuarioResource($sesion['usuario']),
        ]);
    }
}