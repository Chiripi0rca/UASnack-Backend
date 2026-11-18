<?php

// Carpeta (namespace) de las clases que hablan con Stripe (aquí también viven CuentasStripe y TarjetasStripe).
namespace App\Services\Stripe;

// El error del proyecto: bootstrap/app.php lo convierte en { error: { codigo, mensaje } }.
use App\Exceptions\ErrorNegocio;
// Excepción base de Stripe: todas sus fallas (sin red, llave mala, datos rechazados) heredan de ella.
use Stripe\Exception\ApiErrorException;
// Cliente de la API de Stripe: desde él se hacen todas las llamadas.
use Stripe\StripeClient;

// Lo único que habla con la API de Stripe para COBRAR un pedido (#61).
// Va aparte de PagoService, igual que CuentasStripe y TarjetasStripe, para que las pruebas lo simulen.
// Todo lo de aquí pasa en la cuenta de la TIENDA (cobro directo, §9.3):
// por eso cada llamada lleva stripe_account.
class CobrosStripe
{
    // Copia una tarjeta guardada del alumno (vive en la cuenta de la plataforma) a la cuenta de la tienda.
    // Hace falta porque el cobro nace en la tienda, y Stripe solo cobra con tarjetas de esa misma cuenta.
    //   $idCliente → cliente del alumno en la plataforma (cus_…)
    //   $idMetodo  → la tarjeta guardada (pm_…)
    //   $idCuenta  → la cuenta de la tienda (acct_…)
    // Regresa el id de la copia (otro pm_…), que solo sirve en esa tienda.
    public function copiarTarjeta(string $idCliente, string $idMetodo, string $idCuenta): string
    {
        // Le pide a Stripe crear, en la cuenta de la tienda, una copia de la tarjeta de ese cliente.
        $copia = $this->llamar(fn (StripeClient $stripe) => $stripe->paymentMethods->create(
            ['customer' => $idCliente, 'payment_method' => $idMetodo],
            ['stripe_account' => $idCuenta],
        ));

        // Regresa solo el id de la copia.
        return $copia->id;
    }

    // Crea el cobro (PaymentIntent) directo en la cuenta de la tienda.
    //   $datos    → lo que arma PagoService: monto en centavos, moneda, comisión, tarjeta…
    //   $idCuenta → la cuenta de la tienda (acct_…)
    // Regresa:
    //   id            → pi_…, se guarda en pagos.id_payment_intent
    //   client_secret → va a la app para que confirme el cobro con el SDK de Stripe
    // #75 (Daniel) agrega aquí la llave de idempotencia para que un cobro no se repita.
    public function crearIntento(array $datos, string $idCuenta): array
    {
        // Le pide a Stripe crear el cobro en la cuenta de la tienda.
        $intento = $this->llamar(fn (StripeClient $stripe) => $stripe->paymentIntents->create(
            $datos,
            ['stripe_account' => $idCuenta],
        ));

        // Regresa solo lo que se necesita, como arreglo simple (más fácil de simular en las pruebas).
        return ['id' => $intento->id, 'client_secret' => $intento->client_secret];
    }

    // Vuelve a pedir un cobro ya creado (#148). Se usa cuando la app repite POST /pedidos con la misma
    // clave: el client_secret no se guarda en nuestra base, así que se le pregunta a Stripe.
    // Regresa lo mismo que crearIntento.
    public function obtenerIntento(string $idIntento, string $idCuenta): array
    {
        $intento = $this->llamar(fn (StripeClient $stripe) => $stripe->paymentIntents->retrieve(
            $idIntento,
            [],
            ['stripe_account' => $idCuenta],
        ));

        return ['id' => $intento->id, 'client_secret' => $intento->client_secret];
    }

    // Hace la llamada a Stripe y convierte cualquier falla en el error del proyecto.
    // Es una copia del llamar() de CuentasStripe y TarjetasStripe: la app recibe los mismos códigos
    // de error venga de donde venga la falla.
    // $llamada es una función que recibe el cliente de Stripe y hace la petición.
    private function llamar(callable $llamada): mixed
    {
        // Lee STRIPE_SECRET del .env (a través de config/services.php).
        $llave = config('services.stripe.secret');

        // Si la llave no está puesta en el servidor, no tiene caso intentar: avisa con 503.
        if (! $llave) {
            throw new ErrorNegocio(
                'STRIPE_SIN_CONFIGURAR',
                'Los pagos todavía no están configurados en el servidor.',
                503,
            );
        }

        // try: intenta la llamada. Si Stripe lanza un error, salta al catch en vez de tronar la app.
        try {
            // Crea el cliente de Stripe con la llave y ejecuta la llamada que nos pasaron.
            return $llamada(new StripeClient($llave));
        } catch (ApiErrorException $error) {
            // Guarda el detalle técnico en el log del servidor (el alumno no lo ve).
            report($error);

            // Responde con el formato de siempre: 502 = el servicio de afuera (Stripe) falló.
            throw new ErrorNegocio(
                'STRIPE_NO_DISPONIBLE',
                'No pudimos conectar con el servicio de pagos. Intenta de nuevo en un momento.',
                502,
            );
        }
    }
}