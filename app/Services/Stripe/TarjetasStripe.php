<?php

// Carpeta (namespace) de las clases que hablan con Stripe. Aquí también vive CuentasStripe (#109).
namespace App\Services\Stripe;

// El error del proyecto: bootstrap/app.php lo convierte en { error: { codigo, mensaje } }.
use App\Exceptions\ErrorNegocio;
// Excepción base de Stripe: todas sus fallas (sin red, llave mala, cliente inexistente) heredan de ella.
use Stripe\Exception\ApiErrorException;
// Cliente de la API de Stripe: desde él se hacen todas las llamadas.
use Stripe\StripeClient;

// Lo único que habla con la API de Stripe para las tarjetas guardadas de los alumnos (#188; después, #65).
// Va aparte de PagoService, igual que CuentasStripe, para que las pruebas lo simulen sin llamar a Stripe.
// Las tarjetas viven en la cuenta de la PLATAFORMA, no en la de la tienda:
// por eso aquí nunca se manda stripe_account.
class TarjetasStripe
{
    // Regresa las tarjetas guardadas de un cliente de Stripe (cus_…), tal como las da Stripe
    // (objetos PaymentMethod). PagoService::metodosDe las traduce a la forma del contrato.
    public function tarjetasDe(string $idCliente): array
    {
        // Pide a Stripe los métodos de pago de ese cliente:
        //   type 'card' → solo tarjetas (no otros métodos de pago)
        //   limit 20    → máximo 20; un alumno nunca tendrá tantas
        $lista = $this->llamar(fn (StripeClient $stripe) => $stripe->customers->allPaymentMethods(
            $idCliente,
            ['type' => 'card', 'limit' => 20],
        ));

        // Stripe regresa una "lista" con datos extra (si hay más páginas, etc.).
        // ->data es solo el arreglo de tarjetas.
        return $lista->data;
    }

    // Hace la llamada a Stripe y convierte cualquier falla en el error del proyecto.
    // Es una copia del llamar() de CuentasStripe, para que la app reciba los mismos códigos de error
    // venga de donde venga la falla.
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
            // Guarda el detalle técnico del error en el log del servidor (el alumno no lo ve).
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
