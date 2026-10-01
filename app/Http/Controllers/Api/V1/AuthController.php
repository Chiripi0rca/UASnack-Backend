<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarUsuarioRequest;
use App\Http\Requests\VerificarCorreoRequest;
use Illuminate\Support\Facades\DB;
use App\Models\Usuario;
use App\Models\VerificacionCorreo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use App\Http\Requests\ReenviarCodigoRequest;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{


    private const MAX_INTENTOS = 5;

    // Cuántos minutos dura un código
    private const MINUTOS_EXPIRACION = 10;

    // Cuántos segundos hay que esperar para pedir otro código
    private const SEGUNDOS_REENVIO = 60;

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

        // La verificación se revisa DESPUÉS de la contraseña ademas sin correo verificado no se entrega token
        if (! $usuario->correo_verificado_en) {
            return response()->json([
                'error' => [
                    'codigo' => 'CORREO_NO_VERIFICADO',
                    'mensaje' => 'Verifica tu correo institucional para iniciar sesión.',
                ],
            ], 403);
        }

        return response()->json([
            // createToken genera el token en la tabla personal_access_tokens, plainTextToken es el texto que el cliente enviará como "Bearer <token>".
            'token' => $usuario->createToken('cliente')->plainTextToken,
            // only() elige qué datos salen. Se deja fuera hash_password y todo lo sensible.
            'usuario' => $usuario->only([
                'id', 'nombre', 'apellido', 'correo_institucional',
                'id_facultad', 'rol', 'reputacion', 'correo_verificado_en',
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

    //Recibe correo y codigo, si el codigo es correcto, marca el correo como verificado
    public function verificar(VerificarCorreoRequest $request): JsonResponse
    {
       $usuario = Usuario::where('correo_institucional', $request->correo_institucional)->first();//buscamos en usuarios el correo que llego

        //si el correo no existe se responde igual que con un codigo malo para no revelar cuantos correos hay 
        if(! $usuario){
            return $this->respuestaError('CODIGO_INVALIDO', 'El codigo es incorrecto.', 422);
        }

        //si el correo ya esta verificado manda el error
        if($usuario->correo_verificado_en){
            return $this->respuestaError('CORREO_YA_VERIFICADO', 'Este correo ya fue verificado', 409);
        }

        // El código está cifrado y no se puede buscar directo: se toma el último pendiente del usuario.
        $pendiente = VerificacionCorreo::where('id_usuario', $usuario->id)
        ->where('tipo', 'verificacion')
        ->whereNull('consumido_en')
        ->latest()
        ->first();

        //si no hay codigo pendiente o ya paso el tiempo de expiracion, se responde esto
        if(! $pendiente || $pendiente->expira_en->isPast()){
            return $this->respuestaError('CODIGO_EXPIRADO', 'El código expiró. Solicita uno nuevo.', 422);
        }

        // se verifica cuantos numeros de intentos se han hecjo, si es igual a 5 o mas de 5 se manda el error
        if($pendiente->intentos >= self::MAX_INTENTOS){
            return $this->respuestaError('DEMASIADOS_INTENTOS', 'Demasiados intentos. Solicita un codigo nuevo.', 429);
        }

        //se compara hasheando el codigo que llego con el que codigo hasheado de la bd
        if(! Hash::check($request->codigo, $pendiente->codigo_hash)){
            $pendiente->increment('intentos');//se suma la columna intentos por el fallo 
            return $this->respuestaError('CODIGO_INVALIDO', 'El codigo es incorrecto.', 422);
        }

        //se marca el codigo como usado y el correo como verificado
        DB::transaction(function () use ($usuario, $pendiente) {
            $pendiente->update(['consumido_en' => now()]);

            // Se asigna directo porque correo_verificado_en no está en el $fillable de Usuario.
            $usuario->correo_verificado_en = now();
            $usuario->save();
        });

       return response()->json([
            // createToken genera el token en la tabla personal_access_tokens, plainTextToken es el texto que el cliente enviará como "Bearer <token>".
            'token' => $usuario->createToken('cliente')->plainTextToken,
            // only() elige qué datos salen. Se deja fuera hash_password y todo lo sensible.
            'usuario' => $usuario->only([
                'id', 'nombre', 'apellido', 'correo_institucional',
                'id_facultad', 'rol', 'reputacion', 'correo_verificado_en',
            ]),
        ]);


    }

    public function reenviarCodigo(ReenviarCodigoRequest $request): JsonResponse
    {
        // se guarda la respuesta para usarla en la funcion
        $respuestaExito = response()->json([
            'mensaje' => 'Si el correo está registrado y pendiente de verificación, se envió un nuevo código.',
            'reenviar_en_segundos' => self::SEGUNDOS_REENVIO,
        ]);

        //se busca el usuario por correo institucional
        $usuario = Usuario::where('correo_institucional', $request->correo_institucional)->first();

        //si el usuario no existe o el codigo ya esta verificado se manda repsuesta de exito
        if(! $usuario || $usuario->correo_verificado_en){
            return $respuestaExito;
        }

        //se guarda los segundos que faltan
        $segundos = $this->segundosParaReenviar($usuario);


        //si no ha pasdado el minuto, manda error
        if($segundos > 0){
            return response()->json([
                'error' => [
                    'codigo' => 'REENVIO_MUY_PRONTO',
                    'mensaje' => "Espera {$segundos} segundos para solicitar otro codigo."
                ],
                'reenviar_en_segundos' => $segundos,
            ], 429);
        }

        //invalida los codigos anteriores y genera uno nuevo
        $this->enviarCodigoVerificacion($usuario);

        return $respuestaExito;

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


    // formato de respuesta a errores
    private function respuestaError(string $codigo, string $mensaje, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'codigo' => $codigo,
                'mensaje' => $mensaje,
            ],
        ], $status);
    }

    //invalida los codigos pendientes, genera uno nuevo y lo guarda cifrado
    private function enviarCodigoVerificacion(Usuario $usuario): void
    {
        //se busca los otros codigos generados para marcarlos como consumidos
        VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', 'verificacion')
            ->whereNull('consumido_en')
            ->update(['consumido_en' => now()]);

        //se genera un nuevo codigo
        $codigo = (string) random_int(100000, 999999);

        //se crea el nuevo codigo de verificacion
        VerificacionCorreo::create([
            'id_usuario' => $usuario->id,
            'tipo' => 'verificacion',
            'codigo_hash' => Hash::make($codigo),
            'expira_en' => now()->addMinutes(self::MINUTOS_EXPIRACION)
        ]);

        // TODO: conectar aquí el envío del correo con el código ($usuario->correo_institucional y $codigo).
        // Mientras tanto, en local el código se escribe en storage/logs/laravel.log para poder probar.
        if (app()->environment('local')) {
            Log::info("Código de verificación para {$usuario->correo_institucional}: {$codigo}");
        }
    }

    //regresa cuantos segundos faltan para poder pedir otro codigo
    private function segundosParaReenviar(Usuario $usuario): int
    {
        //toma el ultimo codigo que se le genero a este usuario
        $ultimo = VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', 'verificacion')
            ->latest()
            ->first();

            //si no se obtuvo codigo se regresa 0
            if(! $ultimo){
                return 0;
            }

            //calcula cuándo se puede reenviar: fecha del último código + 60 segundos.
         $disponibleEn = $ultimo->creado_en->copy()->addSeconds(self::SEGUNDOS_REENVIO);

         //regresa los segundos que faltan, o 0 si ya pasó. De adentro hacia afuera
         return max(0, (int) ceil(now()->diffInSeconds($disponibleEn, false)));
    }

}