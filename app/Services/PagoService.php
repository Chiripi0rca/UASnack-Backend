<?php

namespace App\Services;

use App\Models\Pedido;
use App\Models\Tienda;
use App\Models\Usuario;
use App\Services\Stripe\CuentasStripe;
use Illuminate\Support\Facades\DB;
use App\Services\Stripe\TarjetasStripe;
use Stripe\PaymentMethod;
use App\Enums\EstadoPago;
use App\Exceptions\ErrorNegocio;
use App\Models\Pago;
use App\Services\Stripe\CobrosStripe;

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

    // Laravel crea PagoService y le pasa solas estas dos clases:
    //   $cuentas  → cuentas de Stripe de las tiendas (#109)
    //   $tarjetas → tarjetas guardadas de los alumnos (#188)
    //   $cobros   → cobros en la cuenta de la tienda (#61)
    public function __construct(
        private CuentasStripe $cuentas,
        private TarjetasStripe $tarjetas,
        private CobrosStripe $cobros,
    ) {
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
    //
    // #77: un evento (por su id) se procesa una sola vez. Se anota y se atiende en la misma transacción:
    // si algo falla, la anotación se deshace y el reintento de Stripe sí lo procesa.
    public function manejarWebhook(array $evento): void
    {
        DB::transaction(function () use ($evento) {
            if (! $this->anotarEvento($evento)) {
                return;
            }

            match ($evento['type'] ?? null) {
                'account.updated' => $this->cuentaActualizada($evento['account'] ?? $evento['data']['object']['id'] ?? null),
                default => null,
            };
        });
    }

    // #77: true si el evento es nuevo, false si ya estaba anotado.
    // insertOrIgnore no lanza error al repetirse la llave (no daña la transacción) y regresa 0 renglones.
    // Si dos avisos iguales llegan a la vez, el segundo espera al primero y luego ve que ya existe.
    private function anotarEvento(array $evento): bool
    {
        return DB::table('eventos_stripe')->insertOrIgnore([
            'id_evento' => $evento['id'],
            'tipo' => $evento['type'] ?? 'desconocido',
        ]) === 1;
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
        // Moneda de todos los cobros: pesos mexicanos.
    private const MONEDA = 'mxn';

    // #61: cobra el pedido directo en la cuenta de la tienda (§9.3) y deja el pago en "pendiente".
    // Lo llama PedidoService::crear (#148) después de guardar el pedido, en la misma transacción.
    // $idMetodoPago: la tarjeta guardada que eligió el alumno (pm_…), o null si captura una nueva.
    public function crearIntento(
        Usuario $usuario,
        string $monto,
        Tienda $tienda,
        Pedido $pedido,
        ?string $idMetodoPago = null,
    ): array {
        // 1. La tienda debe tener su cuenta de Stripe habilitada.
        $this->asegurarQuePuedeCobrar($tienda);

        // 2. Arma el cobro: monto y comisión en centavos.
        $centavos = $this->aCentavos($monto);
        $comision = $this->comisionDe($centavos);
        $datos = $this->datosDelCobro($pedido, $centavos, $comision);

        // 3. Con tarjeta guardada, se pega al cobro una copia de ella en la cuenta de la tienda.
        if ($idMetodoPago) {
            $datos['payment_method'] = $this->tarjetaEnLaTienda($usuario, $idMetodoPago, $tienda);
        }

        // 4. Crea el cobro en Stripe y guarda el pago "pendiente" (el webhook de #62 lo confirma).
        $intento = $this->cobros->crearIntento($datos, $tienda->id_cuenta_stripe);
        $this->registrarPago($pedido, $intento['id'], $monto, $comision);

        // 5. Lo que necesita la app para confirmar el cobro con el SDK de Stripe.
        return [
            'client_secret' => $intento['client_secret'],
            'stripe_account' => $tienda->id_cuenta_stripe,
            'id_payment_intent' => $intento['id'],
        ];
    }

    // #148: los datos para pagar un pedido que ya tiene su cobro creado. Solo aplica si la app repite
    // POST /pedidos con la misma clave: no se crea otro cobro, se regresa el mismo.
    // null si el pedido no tiene cobro todavía.
    public function reanudarCobro(Pedido $pedido): ?array
    {
        $pago = $pedido->pagos()->latest('id')->first();
        $idCuenta = $pedido->tienda?->id_cuenta_stripe;

        if (! $pago || ! $idCuenta) {
            return null;
        }

        $intento = $this->cobros->obtenerIntento($pago->id_payment_intent, $idCuenta);

        return [
            'client_secret' => $intento['client_secret'],
            'stripe_account' => $idCuenta,
            'id_payment_intent' => $pago->id_payment_intent,
        ];
    }

    // Sin cuenta de Stripe habilitada, la tienda no puede cobrar (§9.2).
    // #148 ya lo revisa; esto es una segunda capa por si alguien llama crearIntento desde otro lado.
    private function asegurarQuePuedeCobrar(Tienda $tienda): void
    {
        if (! $tienda->id_cuenta_stripe || ! $tienda->cuenta_habilitada) {
            throw new ErrorNegocio('TIENDA_SIN_PAGOS', 'Esta tienda todavía no puede recibir pagos.', 409);
        }
    }

    // Stripe cobra en centavos y sin decimales: "85.00" → 8500.
    // round() evita que un 84.9999… termine en 8499.
    private function aCentavos(string $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    // La comisión de la plataforma en centavos, con el porcentaje de UASNACK_COMISION_PCT.
    // Con 5 %: de 8500 salen 425 (4.25 pesos).
    private function comisionDe(int $centavos): int
    {
        return (int) round($centavos * (float) config('services.stripe.comision_pct') / 100);
    }

    // Lo que se le manda a Stripe para crear el cobro.
    private function datosDelCobro(Pedido $pedido, int $centavos, int $comision): array
    {
        $datos = [
            'amount' => $centavos,                // cuánto cobrar, en centavos
            'currency' => self::MONEDA,           // pesos mexicanos
            'payment_method_types' => ['card'],   // solo tarjeta (§9)
            'metadata' => [                       // datos nuestros: regresan en el webhook (#62)
                'id_pedido' => (string) $pedido->id,
                'codigo_pedido' => $pedido->codigo_pedido,
            ],
        ];

        // Con 0 % de comisión no se aparta nada para la plataforma.
        if ($comision > 0) {
            $datos['application_fee_amount'] = $comision;
        }

        return $datos;
    }

    // Revisa que la tarjeta sea del alumno y la copia a la cuenta de la tienda.
    // Regresa el id de la copia, que es el que se pega al cobro.
    private function tarjetaEnLaTienda(Usuario $usuario, string $idMetodoPago, Tienda $tienda): string
    {
        // Una tarjeta ajena responde 404, igual que cualquier cosa de otro: nadie cobra con la tarjeta de otro.
        if (! $this->esTarjetaDe($usuario, $idMetodoPago)) {
            throw new ErrorNegocio('NO_ENCONTRADO', 'No encontramos esa tarjeta entre las que tienes guardadas.', 404);
        }

        return $this->cobros->copiarTarjeta($usuario->id_cliente_stripe, $idMetodoPago, $tienda->id_cuenta_stripe);
    }

    // ¿Está esta tarjeta entre las guardadas del alumno? (las mismas de GET /pagos/metodos, #188)
    private function esTarjetaDe(Usuario $usuario, string $idMetodoPago): bool
    {
        // Sin cliente en Stripe, el alumno no tiene ninguna tarjeta guardada.
        if (! $usuario->id_cliente_stripe) {
            return false;
        }

        // collect()->contains revisa si alguna de sus tarjetas tiene ese id.
        return collect($this->tarjetas->tarjetasDe($usuario->id_cliente_stripe))
            ->contains(fn (PaymentMethod $metodo) => $metodo->id === $idMetodoPago);
    }

    // Guarda el renglón en pagos. Nace "pendiente": el webhook (#62) lo pasa a "pagado" o "fallido".
    private function registrarPago(Pedido $pedido, string $idPaymentIntent, string $monto, int $comision): void
    {
        Pago::create([
            'id_pedido' => $pedido->id,
            'id_payment_intent' => $idPaymentIntent,
            'monto' => $monto,
            'comision_plataforma' => $comision / 100,   // de centavos a pesos: 425 → 4.25
            'estado' => EstadoPago::Pendiente,
        ]);
    }

    // #188: tarjetas guardadas del alumno, tal como las regresa GET /pagos/metodos.
        // #188: tarjetas guardadas del alumno, tal como las regresa GET /pagos/metodos.
    // Recibe al alumno y regresa una lista de tarjetas, cada una como { id, marca, ultimos4, vence }.
    // El número completo de la tarjeta nunca llega a nuestro servidor: Stripe no lo da.
    public function metodosDe(Usuario $usuario): array
    {
        // Revisa si el alumno tiene cliente en Stripe (columna id_cliente_stripe, #108).
        // Si está vacía, nunca ha guardado una tarjeta (el cliente lo crea la #65 con la primera).
        if (! $usuario->id_cliente_stripe) {
            // Regresa la lista vacía sin llamar a Stripe: no hay nada que consultar.
            return [];
        }

        // Le pide a Stripe las tarjetas del cliente de este alumno.
        // Si Stripe falla, TarjetasStripe ya lanza el error con el formato del proyecto.
        $tarjetas = $this->tarjetas->tarjetasDe($usuario->id_cliente_stripe);

        // array_map recorre cada tarjeta que regresó Stripe y la convierte en un arreglo
        // con los cuatro campos del contrato. El resultado es la lista que sale en "datos".
        return array_map(fn (PaymentMethod $metodo) => [
            // Id de la tarjeta en Stripe (pm_…). La app lo usa para elegirla al pagar o para borrarla (#65).
            'id' => $metodo->id,

            // Marca de la tarjeta: visa, mastercard, amex…
            'marca' => $metodo->card->brand,

            // Últimos 4 dígitos, por ejemplo "4242".
            'ultimos4' => $metodo->card->last4,

            // Fecha de vencimiento. Stripe da el mes (3) y el año (2028) por separado.
            // sprintf los junta en el texto "03/28":
            //   %02d  escribe el número con 2 dígitos, rellenando con 0 a la izquierda (3 → "03")
            //   % 100 se queda con los dos últimos dígitos del año (2028 → 28)
            'vence' => sprintf('%02d/%02d', $metodo->card->exp_month, $metodo->card->exp_year % 100),
        ], $tarjetas);
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
