<?php

namespace App\Services;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorNegocio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Pedidos del alumno: cada método es un paso de la máquina de estados (§6 del documento general).
//   voySaliendo → #58 (Alfredo). Mete a cocina un pedido "en espera" (§7.3).
// ESQUELETO (Sprint 04). Los completa su tarea y cambia SOLO el contenido:
//   crear → #148 (José Ángel) ✓, con el stock de #192
//   pedidoPorClave y sinRepetirClave → #74 (Ricardo) ✓
//   marcarPagado → #62 (Ricardo)
class PedidoService
{
    // Laravel crea estos servicios y los pasa aquí solo (inyección de dependencias).
    //   $capacidad → franjas, cupo de cocina y tiempo de espera
    //   $catalogo  → stock y total calculado en el servidor
    //   $pagos     → el cobro: PedidoService nunca toca la API de Stripe
    public function __construct(
        private CapacidadService $capacidad,
        private CatalogoService $catalogo,
        private PagoService $pagos,
    ) {
    }

    // #148: crea el pedido en PENDIENTE_PAGO y su cobro. Los datos de pago van en $pedido->pago.
    // Con una clave repetida regresa el pedido que ya existe (wasRecentlyCreated = false), sin tocar
    // stock ni cupo y sin cobrar otra vez.
    // $franja es la hora de RECOGER; null en modo "voy saliendo", que no reserva cocina.
    public function crear(
        Usuario $usuario,
        int $idTienda,
        array $lineas,
        string $modoRetiro,
        ?Carbon $franja,
        string $claveIdem,
    ): Pedido {
        $pedido = $this->pedidoPorClave($claveIdem, $usuario)
            ?? $this->sinRepetirClave(
                $claveIdem,
                $usuario,
                fn () => $this->crearNuevo($usuario, $idTienda, $lineas, $modoRetiro, $franja, $claveIdem),
            );

        return $pedido->wasRecentlyCreated ? $pedido : $this->conDatosDePago($pedido);
    }

    // Todo lo de un pedido nuevo. Primero se revisa sin cambiar nada (tienda, stock, total, hora);
    // luego, en una transacción: stock → cupo → pedido → cobro. Si algo falla, no queda nada a medias.
    private function crearNuevo(
        Usuario $usuario,
        int $idTienda,
        array $lineas,
        string $modoRetiro,
        ?Carbon $franja,
        string $claveIdem,
    ): Pedido {
        $tienda = $this->catalogo->tiendaActiva($idTienda); // 404 si no existe o está de baja

        if (! $tienda->cuenta_habilitada) {
            throw new ErrorNegocio('TIENDA_SIN_PAGOS', 'Esta tienda todavía no puede recibir pagos.', 409);
        }

        $this->catalogo->verificarStock($lineas);

        // el total sale del servidor; cualquier precio que mande la app se ignora
        $calculo = $this->catalogo->calcularTotal($lineas, $franja);
        if ((int) $calculo['lineas'][0]['id_tienda'] !== $tienda->id) {
            throw new ErrorNegocio('VARIAS_TIENDAS', 'Un pedido solo puede tener productos de una tienda.', 422);
        }

        $minutos = $this->minutosDeCocina($lineas);
        $franja = $this->validarRetiro($tienda, $modoRetiro, $franja, $minutos);

        return DB::transaction(function () use ($usuario, $tienda, $calculo, $modoRetiro, $franja, $minutos, $claveIdem, $lineas) {
            $this->catalogo->descontarStock($lineas);

            // lo que regresa reservar() (en qué franjas se apartó la cocina) se guarda para poder liberarlo
            $reparto = $franja ? $this->capacidad->reservar($tienda, $franja, $minutos) : [];

            $pedido = Pedido::create([
                'codigo_pedido' => $this->nuevoCodigo(),
                'clave_idempotencia' => $claveIdem,
                'id_usuario' => $usuario->id,
                'id_tienda' => $tienda->id,
                'estado' => EstadoPedido::PendientePago,
                'modo_retiro' => $modoRetiro,
                'total' => $calculo['total'],
                'minutos_cocina' => $minutos,
                'franja_inicio' => $franja?->format('Y-m-d H:i:s'),
                'franja_fin' => $franja?->copy()->addMinutes((int) $tienda->duracion_franja_min)->format('Y-m-d H:i:s'),
                'reparto_cocina' => $reparto,
                'pin_retiro' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            ]);

            foreach ($calculo['lineas'] as $linea) {
                $pedido->detalle()->create([
                    'id_producto' => $linea['id_producto'],
                    'cantidad' => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'descuento_aplicado' => $linea['descuento_aplicado'],
                    'subtotal' => $linea['subtotal'],
                    'extras' => $linea['extras'],
                    'quitables' => $linea['quitables'],
                ]);
            }

            // el cobro va al final: si Stripe falla se deshace todo lo de arriba
            $monto = number_format($calculo['total'], 2, '.', '');
            $pedido->setRelation('pago', $this->pagos->crearIntento($usuario, $monto, $tienda, $pedido));

            return $pedido;
        });
    }

    // minutos de cocina del carrito: tiempo_prep_min × cantidad de cada producto
    private function minutosDeCocina(array $lineas): int
    {
        $tiempos = Producto::withoutGlobalScopes()
            ->whereIn('id', array_column($lineas, 'id_producto'))
            ->pluck('tiempo_prep_min', 'id');

        return (int) collect($lineas)->sum(fn (array $l) => $tiempos[$l['id_producto']] * $l['cantidad']);
    }

    // Revisa la hora elegida. Regresa la franja a reservar, o null en "voy saliendo".
    // La hora tiene que ser una de las que la tienda ofrece hoy para este pedido (no vale inventarla).
    private function validarRetiro(Tienda $tienda, string $modoRetiro, ?Carbon $franja, int $minutos): ?Carbon
    {
        if ($modoRetiro === Pedido::RETIRO_VOY_SALIENDO) {
            if (! $this->capacidad->estimarEspera($tienda, $minutos)['voy_saliendo_disponible']) {
                throw new ErrorNegocio('VOY_SALIENDO_NO_DISPONIBLE', 'Ahora no se puede pedir con "voy saliendo". Elige una hora.', 409);
            }

            return null;
        }

        $ofrecidas = $this->capacidad->franjasDisponibles($tienda, now(CapacidadService::ZONA_HORARIA), $minutos, 100);
        $elegida = $franja ? $ofrecidas->first(
            fn (array $f) => $f['fecha'] === $franja->format('Y-m-d') && $f['inicio'] === $franja->format('H:i')
        ) : null;

        if (! $elegida || ! $elegida['disponible']) {
            throw new ErrorNegocio('FRANJA_LLENA', 'Esa hora ya se llenó. Elige otra.', 409);
        }

        return $franja;
    }

    // folio visible (TA-2451). Es único en toda la tabla; si ya se usó, se prueba otro.
    // Pasados 20 intentos usa 6 dígitos, para no quedarse dando vueltas si los de 4 se acaban.
    private function nuevoCodigo(): string
    {
        for ($intento = 0; ; $intento++) {
            $codigo = 'TA-'.($intento < 20 ? random_int(1000, 9999) : random_int(100000, 999999));

            if (! Pedido::withoutGlobalScopes()->where('codigo_pedido', $codigo)->exists()) {
                return $codigo;
            }
        }
    }

    // clave repetida con el pago aún pendiente: la app necesita el client_secret para seguir pagando
    private function conDatosDePago(Pedido $pedido): Pedido
    {
        if ($pedido->estado === EstadoPedido::PendientePago) {
            $pedido->setRelation('pago', $this->pagos->reanudarCobro($pedido));
        }

        return $pedido;
    }

    // #74: el pedido que ya se creó con esta clave, o null si la clave es nueva.
    // Con $usuario, revisa además que el pedido sea suyo: la clave es única en TODA la tabla, así que si
    // otro alumno ya la usó no se le puede regresar su pedido (sería mostrarle datos ajenos) ni crear
    // otro con ella. Se responde 409 y la app genera una clave nueva.
    public function pedidoPorClave(string $claveIdem, ?Usuario $usuario = null): ?Pedido
    {
        // Sin el filtro del encargado (#163): aquí se busca por la clave, venga quien venga
        $pedido = Pedido::withoutGlobalScopes()
            ->where('clave_idempotencia', strtolower($claveIdem))
            ->first();

        if ($pedido && $usuario && $pedido->id_usuario !== $usuario->id) {
            throw new ErrorNegocio(
                'CLAVE_IDEMPOTENCIA_EN_USO',
                'No pudimos registrar tu pedido. Inténtalo de nuevo.',
                409,
            );
        }

        return $pedido;
    }

    // #74: dos peticiones con la misma clave al mismo tiempo (el alumno tocó dos veces o la red reintentó)
    // pueden pasar las dos por pedidoPorClave() antes de que exista el pedido. La restricción UNIQUE de
    // clave_idempotencia deja guardar solo a la primera; la segunda choca aquí y, en vez de responder 500,
    // regresa el pedido que guardó la primera. Así nunca hay dos pedidos ni dos cobros con una clave.
    //
    // crear() (#148) envuelve en esto su transacción:
    //   return $this->sinRepetirClave($claveIdem, $usuario, fn () => DB::transaction(function () { ... }));
    // La transacción va ADENTRO: al chocar ya se deshizo todo (cupo reservado incluido) y se puede volver
    // a consultar. Si choca otra restricción (por ejemplo, un folio repetido), el error se vuelve a lanzar.
    public function sinRepetirClave(string $claveIdem, Usuario $usuario, Closure $crear): Pedido
    {
        try {
            return $crear();
        } catch (UniqueConstraintViolationException $error) {
            return $this->pedidoPorClave($claveIdem, $usuario) ?? throw $error;
        }
    }

    // #62: Stripe confirmó el cobro. Pasa a PAGADO, o a EN_ESPERA en modo "voy saliendo" (§7.3).
    // Nunca se llama desde crear(): el pago solo se da por hecho cuando llega el aviso de Stripe.
    public function marcarPagado(Pedido $pedido): Pedido
    {
        return $pedido;
    }

    // #58: el alumno pidió en modo "voy saliendo" y su pedido quedó pagado pero EN_ESPERA,
    // sin ocupar cupo de ninguna franja. Al tocar "voy saliendo" el pedido entra a cocina:
    //   - pasa de EN_ESPERA a PAGADO, que es el estado con el que aparece en la columna
    //     "Nuevos" de la cola de la tienda para que lo acepte;
    //   - se calcula en ESE momento cuánto va a tardar, con la cola real de la cocina (§7.3).
    // Si el pedido ya no está EN_ESPERA (por ejemplo, el alumno tocó dos veces), lanza
    // ErrorNegocio('PEDIDO_NO_EN_ESPERA', ..., 409) y no cambia nada.
    // Que el pedido sea del alumno que lo pide lo revisa la ruta (policy), no este método.
    public function voySaliendo(Pedido $pedido): Pedido
    {
        // Todo en una transacción: o se guarda el cambio completo, o no se guarda nada.
        return DB::transaction(function () use ($pedido) {
            // Se vuelve a leer el pedido BLOQUEADO (lockForUpdate) hasta que termine la transacción.
            // Si llegan dos peticiones al mismo tiempo, la segunda espera aquí y, cuando le toca,
            // ya ve el pedido en PAGADO: así nunca entra dos veces a cocina.
            $pedido = Pedido::withoutGlobalScopes()->lockForUpdate()->findOrFail($pedido->id);

            // Solo un pedido que sigue esperando puede entrar a cocina.
            if ($pedido->estado !== EstadoPedido::EnEspera) {
                throw new ErrorNegocio('PEDIDO_NO_EN_ESPERA', 'Tu pedido ya no está en espera.', 409);
            }

            // Rango de espera con la carga que tiene la cocina ahorita, sumando los minutos
            // de cocina de este pedido. Regresa ['min' => 16, 'max' => 22, ...].
            $espera = $this->capacidad->estimarEspera($pedido->tienda, $pedido->minutos_cocina);

            // Entra a la cola de la tienda como pedido nuevo.
            $pedido->estado = EstadoPedido::Pagado;
            // Se guarda el extremo alto del rango: lo más que debería esperar el alumno.
            // Si la cocina se destraba, el estimado que vea después solo puede bajar.
            $pedido->tiempo_estimado_min = $espera['max'];
            $pedido->save();

            // Aquí se emitirá el evento PedidoCambioEstado cuando exista (Reverb y push).

            return $pedido;
        });
    }
}
