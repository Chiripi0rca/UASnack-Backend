<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\Usuario;
use App\Models\VerificacionCorreo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

// Todo lo del código de 6 dígitos: generarlo, validarlo y reenviarlo.
class VerificacionCorreoService
{
    private const MAX_INTENTOS = 5;

    // Cuántos minutos dura un código
    private const MINUTOS_EXPIRACION = 10;

    // Cuántos segundos hay que esperar para pedir otro código. Pública porque el controlador la devuelve.
    public const SEGUNDOS_REENVIO = 60;

    // Invalida los códigos pendientes, genera uno nuevo y lo guarda cifrado
    public function enviarCodigo(Usuario $usuario): void
    {
        VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', 'verificacion')
            ->whereNull('consumido_en')
            ->update(['consumido_en' => now()]);

        $codigo = (string) random_int(100000, 999999);

        VerificacionCorreo::create([
            'id_usuario' => $usuario->id,
            'tipo' => 'verificacion',
            'codigo_hash' => Hash::make($codigo),
            'expira_en' => now()->addMinutes(self::MINUTOS_EXPIRACION),
        ]);

        // TODO: conectar aquí el envío del correo con el código ($usuario->correo_institucional y $codigo).
        // Mientras tanto, en local el código se escribe en storage/logs/laravel.log para poder probar.
        if (app()->environment('local')) {
            Log::info("Código de verificación para {$usuario->correo_institucional}: {$codigo}");
        }
    }

    // Valida el código y marca el correo como verificado. Devuelve el usuario ya verificado.
    public function verificar(string $correo, string $codigo): Usuario
    {
        $usuario = Usuario::where('correo_institucional', $correo)->first();

        // Si el correo no existe se responde igual que con un código malo, para no revelar qué correos hay
        if (! $usuario) {
            throw new ErrorNegocio('CODIGO_INVALIDO', 'El codigo es incorrecto.', 422);
        }

        if ($usuario->correo_verificado_en) {
            throw new ErrorNegocio('CORREO_YA_VERIFICADO', 'Este correo ya fue verificado', 409);
        }

        // El código está cifrado y no se puede buscar directo: se toma el último pendiente del usuario.
        $pendiente = VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', 'verificacion')
            ->whereNull('consumido_en')
            ->latest()
            ->first();

        if (! $pendiente || $pendiente->expira_en->isPast()) {
            throw new ErrorNegocio('CODIGO_EXPIRADO', 'El código expiró. Solicita uno nuevo.', 422);
        }

        if ($pendiente->intentos >= self::MAX_INTENTOS) {
            throw new ErrorNegocio('DEMASIADOS_INTENTOS', 'Demasiados intentos. Solicita un codigo nuevo.', 429);
        }

        // El intento fallido se suma ANTES de lanzar el error; si no, nunca se contaría
        if (! Hash::check($codigo, $pendiente->codigo_hash)) {
            $pendiente->increment('intentos');
            throw new ErrorNegocio('CODIGO_INVALIDO', 'El codigo es incorrecto.', 422);
        }

        DB::transaction(function () use ($usuario, $pendiente) {
            $pendiente->update(['consumido_en' => now()]);

            // Se asigna directo porque correo_verificado_en no está en el $fillable de Usuario.
            $usuario->correo_verificado_en = now();
            $usuario->save();
        });

        return $usuario;
    }

    // Genera un código nuevo si ya pasó el tiempo de espera.
    // Si el correo no existe o ya está verificado no hace nada, para no revelar qué correos hay.
    public function reenviar(string $correo): void
    {
        $usuario = Usuario::where('correo_institucional', $correo)->first();

        if (! $usuario || $usuario->correo_verificado_en) {
            return;
        }

        $segundos = $this->segundosParaReenviar($usuario);

        // Retry-After es el header que lee el cliente para arrancar el contador
        if ($segundos > 0) {
            throw new ErrorNegocio(
                'REENVIO_MUY_PRONTO',
                "Espera {$segundos} segundos para solicitar otro codigo.",
                429,
                ['Retry-After' => $segundos],
            );
        }

        $this->enviarCodigo($usuario);
    }

    // Regresa cuántos segundos faltan para poder pedir otro código, o 0 si ya se puede
    private function segundosParaReenviar(Usuario $usuario): int
    {
        $ultimo = VerificacionCorreo::where('id_usuario', $usuario->id)
            ->where('tipo', 'verificacion')
            ->latest()
            ->first();

        if (! $ultimo) {
            return 0;
        }

        $disponibleEn = $ultimo->creado_en->copy()->addSeconds(self::SEGUNDOS_REENVIO);

        return max(0, (int) ceil(now()->diffInSeconds($disponibleEn, false)));
    }
}