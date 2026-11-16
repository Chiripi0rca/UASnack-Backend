<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\Stripe\CuentasStripe;
use Illuminate\Support\Facades\DB;

// ESQUELETO (Sprint 04). Todo lo que habla con Stripe vive aquí: PedidoService nunca toca su API.
// Cada método lo completa su tarea y cambia SOLO el contenido, no el nombre ni lo que regresa:
//   crearCuentaConectada, generarLinkOnboarding, estadoCuenta → #109 (Ricardo) ✓
//   crearIntento → #61 (Alfredo). #75 (Daniel) agrega que un cobro no se repita.
//   metodosDe, crearSetupIntent, eliminarMetodo → #65 y #188 (Alfredo)
//   historialDe → #69 (Alfredo)
//
// Por ahora regresan DATOS DE EJEMPLO para que #148 y las pantallas no tengan que esperar a Stripe.
// stripe/stripe-php lo instala #176 (José Ángel).
class PagoService
{
    // Estados de la cuenta de la tienda que ve el encargado en "Configura tus pagos" (#147)
    public const VERIFICADO = 'verificado';
    public const PENDIENTE = 'pendiente';
    public const RECHAZADO = 'rechazado';

    public function __construct(private CuentasStripe $cuentas)
    {
    }

    // #109: crea la cuenta conectada de la tienda en Stripe (ver CuentasStripe) y guarda tiendas.id_cuenta_stripe.
    // Regresa el id de la cuenta. Si la tienda ya tiene una, regresa esa: nunca se crean dos.
    public function crearCuentaConectada(Tienda $tienda): string
    {
        // La tienda se bloquea mientras se crea: si llegan dos peticiones juntas (doble clic),
        // la segunda espera y, al seguir, ya encuentra la cuenta que guardó la primera.
        return DB::transaction(function () use ($tienda) {
            $tienda = Tienda::lockForUpdate()->findOrFail($tienda->id);

            if ($tienda->id_cuenta_stripe) {
                return $tienda->id_cuenta_stripe;
            }

            $tienda->id_cuenta_stripe = $this->cuentas->crearCuenta($tienda);
            $tienda->save();

            return $tienda->id_cuenta_stripe;
        });
    }

    // #109: URL temporal de Stripe donde el encargado da de alta su cuenta bancaria.
    // Si la tienda todavía no tiene cuenta, se crea aquí. El enlace se pide cada vez (caduca en minutos).
    // Al terminar, Stripe regresa al encargado a "Configura tus pagos"; si el enlace ya caducó, a la
    // misma vista, donde vuelve a tocar el botón y se pide uno nuevo.
    public function generarLinkOnboarding(Tienda $tienda): string
    {
        $idCuenta = $this->crearCuentaConectada($tienda);
        $vista = rtrim(config('app.panel_url'), '/').'/pagos';

        return $this->cuentas->crearEnlaceDeAlta($idCuenta, $vista, $vista);
    }

    // #146: atiende un aviso de Stripe ya verificado. Lo que no conocemos se ignora (se responde 200):
    // si se respondiera error, Stripe lo reintentaría por días sin que sirva de nada.
    // #62 agrega aquí los eventos de pago (payment_intent.*).
    public function manejarWebhook(array $evento): void
    {
        match ($evento['type'] ?? null) {
            'account.updated' => $this->cuentaActualizada($evento['account'] ?? $evento['data']['object']['id'] ?? null),
            default => null,
        };
    }

    // #146: Stripe avisa que la cuenta de una tienda cambió. No nos fiamos del contenido del aviso:
    // se vuelve a preguntar a Stripe y se guarda el resultado en tiendas.cuenta_habilitada.
    private function cuentaActualizada(?string $idCuenta): void
    {
        $tienda = $idCuenta ? Tienda::where('id_cuenta_stripe', $idCuenta)->first() : null;
        if (! $tienda) {
            return;
        }

        $cuenta = $this->cuentas->consultarCuenta($idCuenta);

        if ($tienda->cuenta_habilitada !== $cuenta['puede_cobrar']) {
            $tienda->cuenta_habilitada = $cuenta['puede_cobrar'];
            $tienda->save();
        }
    }

    // #109: "verificado", "pendiente" o "rechazado".
    // Se le pregunta a Stripe en el momento y se guarda en tiendas.cuenta_habilitada, que es lo que revisa
    // crear el pedido (#148). Así funciona aunque todavía no llegue el aviso de Stripe (#146).
    public function estadoCuenta(Tienda $tienda): string
    {
        // Sin cuenta todavía: falta empezar el alta. No hace falta preguntarle a Stripe.
        if (! $tienda->id_cuenta_stripe) {
            return self::PENDIENTE;
        }

        $cuenta = $this->cuentas->consultarCuenta($tienda->id_cuenta_stripe);

        if ($tienda->cuenta_habilitada !== $cuenta['puede_cobrar']) {
            $tienda->cuenta_habilitada = $cuenta['puede_cobrar'];
            $tienda->save();
        }

        if ($cuenta['puede_cobrar']) {
            return self::VERIFICADO;
        }

        return $cuenta['rechazada'] ? self::RECHAZADO : self::PENDIENTE;
    }

    // #61: crea el PaymentIntent DIRECTO en la cuenta de la tienda y guarda el renglón en pagos.
    // Lo llama PedidoService::crear (#148). Los dos primeros valores van a la app para que confirme
    // el cobro con el SDK de Stripe. Si el alumno paga con una tarjeta guardada, aquí se copia a la
    // cuenta de la tienda (la tarjeta vive en la plataforma y el cobro nace en la tienda).
    public function crearIntento(Usuario $usuario, string $monto, Tienda $tienda, Pedido $pedido): array
    {
        return [
            'client_secret' => 'pi_ejemplo_secret_ejemplo',
            'stripe_account' => 'acct_ejemplo',
            'id_payment_intent' => 'pi_ejemplo',
        ];
    }

    // #188: tarjetas guardadas del alumno, tal como las regresa GET /pagos/metodos.
    public function metodosDe(Usuario $usuario): array
    {
        return [
            ['id' => 'pm_ejemplo', 'marca' => 'visa', 'ultimos4' => '4242', 'vence' => '12/28'],
        ];
    }

    // #65: SetupIntent para guardar una tarjeta sin cobrar. La tarjeta la captura Stripe en la app,
    // nunca pasa por nuestro servidor. Regresa el client_secret.
    public function crearSetupIntent(Usuario $usuario): string
    {
        return 'seti_ejemplo_secret_ejemplo';
    }

    // #65: quita una tarjeta guardada del alumno.
    public function eliminarMetodo(Usuario $usuario, string $idMetodo): void
    {
    }

    // #69: pagos y reembolsos del alumno, los más nuevos primero. Lo paginado lo decide esta tarea.
    public function historialDe(Usuario $usuario): array
    {
        return [
            ['id' => 12, 'codigo_pedido' => 'TA-2451', 'monto' => '85.00', 'estado' => 'pagado', 'creado_en' => '2026-11-09 11:21:00'],
        ];
    }
}
