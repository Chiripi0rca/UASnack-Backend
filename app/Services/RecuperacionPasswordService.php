<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Mail\CodigoRecuperacionMail;
use App\Models\Usuario;
use App\Models\VerificacionCorreo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

// HU-33: recuperar la contraseña olvidada (dos pasos) y cambiarla con sesión.
//
// Los códigos se guardan en verificaciones_correo con tipo = 'recuperacion', con las mismas reglas
// que la verificación del correo: 6 dígitos cifrados, 10 minutos, 5 intentos (VerificacionCorreoService).
// Un código de verificación no sirve para recuperar, ni al revés: cada uno se busca por su tipo.
class RecuperacionPasswordService
{
    private const TIPO = 'recuperacion';

    private const MAX_INTENTOS = 5;

    private const MINUTOS_EXPIRACION = 10;

    // Si ya se mandó un código hace menos de esto, no se manda otro (evita llenar el buzón).
    private const SEGUNDOS_REENVIO = 60;

    // Paso 1 · "Olvidé mi contraseña": manda un código al correo.
    // NO revela si la cuenta existe (CA-02): si el correo no está registrado, si ya se mandó un código
    // hace menos de un minuto o si el correo no sale, no lanza error. El controlador responde 204 siempre.
    public function enviarCodigo(string $correo): void
    {
        $usuario = Usuario::where('correo_institucional', $correo)->first();

        if (! $usuario || $this->seMandoHacePoco($usuario)) {
            return;
        }

        $codigo = (string) random_int(100000, 999999);

        DB::transaction(function () use ($usuario, $codigo) {
            // Solo vale el último código: los anteriores se dan por usados
            VerificacionCorreo::where('id_usuario', $usuario->id)
                ->where('tipo', self::TIPO)
                ->whereNull('consumido_en')
                ->update(['consumido_en' => now()]);

            VerificacionCorreo::create([
                'id_usuario' => $usuario->id,
                'tipo' => self::TIPO,
                'codigo_hash' => Hash::make($codigo),
                'expira_en' => now()->addMinutes(self::MINUTOS_EXPIRACION),
            ]);
        });

        try {
            Mail::to($usuario->correo_institucional)->send(new CodigoRecuperacionMail($codigo, $usuario->nombre));
        } catch (Throwable $e) {
            // Aquí no se responde CORREO_NO_ENVIADO como en el registro: ese error solo puede salir
            // con cuentas que existen, y revelaría cuáles son. Se registra y el alumno puede volver a pedirlo.
            Log::error('No fue posible enviar el código de recuperación.', [
                'usuario_id' => $usuario->id,
                'exception' => $e::class,
            ]);
        }
    }

    // Paso 2 · "Nueva contraseña": con el código correcto, cambia la contraseña y
    // cierra TODAS las sesiones abiertas (CA-04): quien tuviera la cuenta abierta en otro lado sale.
    public function restablecer(string $correo, string $codigo, string $passwordNueva): void
    {
        $usuario = Usuario::where('correo_institucional', $correo)->first();

        // Correo que no existe = mismo error que un código malo, para no revelar qué correos hay
        if (! $usuario) {
            throw new ErrorNegocio('CODIGO_INVALIDO', 'El código es incorrecto.', 422);
        }

        // El código está cifrado y no se puede buscar directo: se toma el último pendiente del usuario
        $pendiente = VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', self::TIPO)
            ->whereNull('consumido_en')
            ->latest()
            ->first();

        if (! $pendiente || $pendiente->expira_en->isPast()) {
            throw new ErrorNegocio('CODIGO_EXPIRADO', 'El código expiró. Solicita uno nuevo.', 422);
        }

        if ($pendiente->intentos >= self::MAX_INTENTOS) {
            throw new ErrorNegocio('DEMASIADOS_INTENTOS', 'Demasiados intentos. Solicita un código nuevo.', 429);
        }

        // El intento fallido se suma ANTES de lanzar el error; si no, nunca se contaría
        if (! Hash::check($codigo, $pendiente->codigo_hash)) {
            $pendiente->increment('intentos');
            throw new ErrorNegocio('CODIGO_INVALIDO', 'El código es incorrecto.', 422);
        }

        DB::transaction(function () use ($usuario, $pendiente, $passwordNueva) {
            $pendiente->update(['consumido_en' => now()]);

            $usuario->hash_password = Hash::make($passwordNueva);

            // Recibir el código prueba que el correo es suyo: si no lo había verificado, queda verificado
            // (correo_verificado_en no está en el $fillable de Usuario: se asigna directo).
            $usuario->correo_verificado_en ??= now();
            $usuario->save();

            $usuario->tokens()->delete();
        });
    }

    // Con sesión · "Cambiar contraseña": pide la actual.
    // Las demás sesiones se cierran; la de este dispositivo sigue abierta para no sacarlo de la app.
    public function cambiar(Usuario $usuario, string $passwordActual, string $passwordNueva): void
    {
        if (! Hash::check($passwordActual, $usuario->hash_password)) {
            throw new ErrorNegocio('PASSWORD_ACTUAL_INCORRECTA', 'Tu contraseña actual no es correcta.', 422);
        }

        DB::transaction(function () use ($usuario, $passwordNueva) {
            $usuario->hash_password = Hash::make($passwordNueva);
            $usuario->save();

            $actual = $usuario->currentAccessToken();
            $usuario->tokens()
                ->when($actual instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $actual->id))
                ->delete();
        });
    }

    private function seMandoHacePoco(Usuario $usuario): bool
    {
        return VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', self::TIPO)
            ->where('creado_en', '>', now()->subSeconds(self::SEGUNDOS_REENVIO))
            ->exists();
    }
}
