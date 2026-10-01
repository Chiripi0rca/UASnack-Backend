<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Registro, inicio y cierre de sesión. Lo del código de verificación lo delega a VerificacionCorreoService.
class AuthService
{
    public function __construct(private VerificacionCorreoService $verificacion)
    {
    }

    // Crea al usuario y su primer código en la misma transacción:
    // si falla el código, no queda un usuario registrado sin forma de verificarse.
    public function registrar(array $datos): Usuario
    {
        return DB::transaction(function () use ($datos) {
            $usuario = Usuario::create([
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'correo_institucional' => $datos['correo_institucional'],
                'id_facultad' => $datos['id_facultad'],
                'hash_password' => Hash::make($datos['password']),
                'telefono' => $datos['telefono'],
                'terminos_aceptados_en' => now(),
            ]);

            $this->verificacion->enviarCodigo($usuario);

            return $usuario;
        });
    }

    // Devuelve ['token' => ..., 'usuario' => Usuario] si el correo y la contraseña son correctos
    public function iniciarSesion(string $correo, string $password): array
    {
        $usuario = Usuario::where('correo_institucional', $correo)->first();

        // Si el correo no existe O la contraseña no coincide, se responde lo mismo
        if (! $usuario || ! Hash::check($password, $usuario->hash_password)) {
            throw new ErrorNegocio('CREDENCIALES_INVALIDAS', 'Correo o contraseña incorrectos.', 401);
        }

        // La verificación se revisa DESPUÉS de la contraseña; sin correo verificado no se entrega token
        if (! $usuario->correo_verificado_en) {
            throw new ErrorNegocio('CORREO_NO_VERIFICADO', 'Verifica tu correo institucional para iniciar sesión.', 403);
        }

        return $this->crearSesion($usuario);
    }

    // Verifica el código y deja al usuario con sesión iniciada
    public function verificarCorreo(string $correo, string $codigo): array
    {
        $usuario = $this->verificacion->verificar($correo, $codigo);

        return $this->crearSesion($usuario);
    }

    // Borra solo el token con el que se hizo la petición
    public function cerrarSesion(Usuario $usuario): void
    {
        $usuario->currentAccessToken()->delete();
    }

    // Único lugar donde se crea el token de Sanctum
    private function crearSesion(Usuario $usuario): array
    {
        return [
            'token' => $usuario->createToken('cliente')->plainTextToken,
            'usuario' => $usuario,
        ];
    }
}