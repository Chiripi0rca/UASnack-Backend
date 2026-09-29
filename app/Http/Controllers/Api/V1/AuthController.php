<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarUsuarioRequest;
use App\Models\Usuario;
use App\Models\VerificacionCorreo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function registro(RegistrarUsuarioRequest $request): JsonResponse
    {
        $usuario = Usuario::create([
            'nombre' => $request->nombre,
            'apellido' => $request->apellido,
            'correo_institucional' => strtolower($request->correo_institucional),
            'id_facultad' => $request->id_facultad,
            'hash_password' => Hash::make($request->password),
            'telefono' => $request->telefono,
            'terminos_aceptados_en' => now(),
        ]);
        $codigo = random_int(100000, 999999);

        VerificacionCorreo::create([
            'id_usuario' => $usuario->id,
            'tipo' => 'verificacion',
            'codigo_hash' => Hash::make($codigo),
            'expira_en' => now()->addMinutes(10),
        ]);

        return response()->json([
            'mensaje' => 'Usuario registrado exitosamente. Se ha enviado un código de verificación al correo institucional.',
            'reenviar_en_segundos' => 60,
        ], 201);
    }
        
        // Recibe correo y contraseña, y si son correctos devuelve un token de Sanctum.
        public function login(LoginRequest $request): JsonResponse
    {
        // Se busca al usuario por su correo, strtolower evita problemas por mayúsculas,
        $usuario = Usuario::where('correo_institucional', strtolower($request->correo_institucional))->first();

        // Si el correo no existe O la contraseña no coincide con el hash guardado, se responde lo mismo.
        if (! $usuario || ! Hash::check($request->password, $usuario->hash_password)) {
            return $this->errorCredenciales();
        }

        return response()->json([
            // createToken genera el token en la tabla personal_access_tokens, plainTextToken es el texto que el cliente enviará como "Bearer <token>".
            'token' => $usuario->createToken('cliente')->plainTextToken,
            // only() elige qué datos salen. Se deja fuera hash_password y todo lo sensible.
            'usuario' => $usuario->only([
                'id', 'nombre', 'apellido', 'correo_institucional',
                'id_facultad', 'reputacion', 'correo_verificado_en',
            ]),
        ]);
    }

    // Borra solo el token con el que se hizo la petición, así que esa sesión deja de funcionar de inmediato
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        // 204 = todo bien, sin contenido que devolver.
        return response()->noContent();
    }

    // Respuesta de error para credenciales incorrectas (401). Para que la web y Android muestren el mensaje tal cual lo manda el servidor.
    private function errorCredenciales(): JsonResponse
    {
        return response()->json([
            'error' => [
                'codigo' => 'CREDENCIALES_INVALIDAS',
                'mensaje' => 'Correo o contraseña incorrectos.',
            ],
        ], 401);
    }
}
