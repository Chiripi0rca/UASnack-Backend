<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;

// ESQUELETO (Sprint 04). Todo lo que habla con Stripe vive aquí: PedidoService nunca toca su API.
// Cada método lo completa su tarea y cambia SOLO el contenido, no el nombre ni lo que regresa:
//   crearCuentaConectada, generarLinkOnboarding, estadoCuenta → #109 (Ricardo)
//   crearIntento → #61 (Alfredo). #75 (Daniel) agrega que un cobro no se repita.
//   metodosDe, crearSetupIntent, eliminarMetodo → #65 y #188 (Alfredo)
//   historialDe → #69 (Alfredo)
//
// Por ahora regresan DATOS DE EJEMPLO para que #148 y las pantallas no tengan que esperar a Stripe.
// stripe/stripe-php lo instala #176 (José Ángel).
class PagoService
{
    // #109: crea la cuenta Express de la tienda en Stripe y guarda tiendas.id_cuenta_stripe.
    // Regresa el id de la cuenta. Si la tienda ya tiene una, regresa esa.
    public function crearCuentaConectada(Tienda $tienda): string
    {
        return 'acct_ejemplo';
    }

    // #109: URL temporal de Stripe donde el encargado da de alta su cuenta bancaria.
    public function generarLinkOnboarding(Tienda $tienda): string
    {
        return 'https://connect.stripe.com/setup/ejemplo';
    }

    // #109: "verificado", "pendiente" o "rechazado".
    public function estadoCuenta(Tienda $tienda): string
    {
        return 'pendiente';
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
