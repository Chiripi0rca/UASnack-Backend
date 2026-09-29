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
    
        public function login(LoginRequest $request): JsonResponse
    {
        $usuario = Usuario::where('correo_institucional', strtolower($request->correo_institucional))->first();

        if (! $usuario || ! Hash::check($request->password, $usuario->hash_password)) {
            return $this->errorCredenciales();
        }

        return response()->json([
            'token' => $usuario->createToken('cliente')->plainTextToken,
            'usuario' => $usuario->only([
                'id', 'nombre', 'apellido', 'correo_institucional',
                'id_facultad', 'reputacion', 'correo_verificado_en',
            ]),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

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
