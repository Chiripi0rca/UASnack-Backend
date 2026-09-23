<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VerificacionCorreo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\RegistrarUsuarioRequest;
use App\Models\Usuario;

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
           'message' => 'Usuario registrado exitosamente. Se ha enviado un código de verificación al correo institucional.',
       ], 201);
   }
}
