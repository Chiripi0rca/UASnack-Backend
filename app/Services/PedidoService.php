<?php

namespace App\Services;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorNegocio;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

// Pedidos del alumno: cada método es un paso de la máquina de estados (§6 del documento general).
//   voySaliendo → #58 (Alfredo). Mete a cocina un pedido "en espera" (§7.3).
class PedidoService
{
    // Laravel crea CapacidadService y lo pasa aquí solo (inyección de dependencias).
    // Se usa para calcular el tiempo de espera en el momento en que el pedido entra a cocina.
    public function __construct(private CapacidadService $capacidad)
    {
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
