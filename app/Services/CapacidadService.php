<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\OcupacionFranja;
use App\Models\Tienda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

// ESQUELETO (Sprint 03). Franjas, cupo y tiempo de espera (§7 del documento general).
// Cada método lo completa su tarea:
//   franjasDisponibles → #52  (Ricardo)
//   reservar, liberar  → #105 (Alfredo)
//   estimarEspera      → #56  (José Ángel)
//
// Funciones de apoyo: ya regresan algo para que nadie espere a otro. Quien tiene la tarea
// cambia SOLO el contenido; el nombre y lo que reciben/regresan no se cambian sin avisar.
//   minutosOcupados → #105 (Alfredo).    Lee ocupacion_franjas: los minutos ya apartados en esa franja.
//   cargaPendiente  → T-45 (Alfredo).    Hoy regresa 0: no hay pedidos en cocina.
//   bloquearFranja  → #171 (José Ángel). Hoy busca la franja SIN bloquearla.
class CapacidadService
{
    // #52: las próximas franjas de la tienda a partir de $fecha, generadas desde su horario
    // (horario_apertura a horario_cierre, cada duracion_franja_min).
    // Marca llena (disponible = false) la franja donde lo que queda no alcanza para $minutos,
    // que son los minutos de cocina del pedido que se está armando.
    // Regresa como máximo $limite franjas, nunca las 72 del día.
    // Lo que queda en cada franja = minutos_cocina de capacidad_franjas - minutosOcupados().
    public function franjasDisponibles(Tienda $tienda, Carbon $fecha, int $minutos, int $limite = 8): Collection
    {
        throw new LogicException('Pendiente: #52');
    }

    // #105: aparta $minutos de cocina en la franja que empieza en $franja.
    // Va dentro de DB::transaction() y lee la franja con bloquearFranja() (#171),
    // así dos pedidos al mismo tiempo no toman el último cupo.
    // Si no alcanza, lanza ErrorNegocio('FRANJA_LLENA', ..., 409).
    public function reservar(Tienda $tienda, Carbon $franja, int $minutos): void
    {
        // Un pedido que no se cocina (#237, tiempo 0) no ocupa cupo.
        if ($minutos <= 0) {
            return;
        }

        // Si quien llama ya abrió una transacción (PedidoService::crear), esta se vuelve un
        // savepoint dentro de ella y el bloqueo dura hasta que termine la de afuera.
        DB::transaction(function () use ($tienda, $franja, $minutos) {
            $capacidad = $this->bloquearFranja($tienda, $franja);

            // Sin renglón de capacidad, la tienda no cocina en esa franja: no cabe nada.
            if (! $capacidad || $this->minutosOcupados($tienda, $franja) + $minutos > $capacidad->minutos_cocina) {
                throw new ErrorNegocio('FRANJA_LLENA', 'Esa franja ya se llenó. Elige otra.', 409);
            }

            $ocupacion = OcupacionFranja::withoutGlobalScopes()->firstOrCreate(
                ['id_tienda' => $tienda->id, 'franja_inicio' => $franja->format('Y-m-d H:i:s')],
                ['minutos_ocupados' => 0],
            );
            $ocupacion->increment('minutos_ocupados', $minutos);
        });
    }

    // #105: devuelve a la franja los minutos de un pedido cancelado o rechazado.
    public function liberar(Tienda $tienda, Carbon $franja, int $minutos): void
    {
        if ($minutos <= 0) {
            return;
        }

        DB::transaction(function () use ($tienda, $franja, $minutos) {
            // Mismo bloqueo que reservar(): una reserva y una liberación de la misma franja no se pisan.
            $this->bloquearFranja($tienda, $franja);

            $ocupacion = OcupacionFranja::withoutGlobalScopes()
                ->where('id_tienda', $tienda->id)
                ->where('franja_inicio', $franja->format('Y-m-d H:i:s'))
                ->first();

            if (! $ocupacion) {
                return;
            }

            // Nunca por debajo de 0.
            $ocupacion->minutos_ocupados = max(0, $ocupacion->minutos_ocupados - $minutos);
            $ocupacion->save();
        });
    }

    // #56: tiempo de espera del modo "voy saliendo" (§7.4):
    //   estimado = cargaPendiente() / capacidad + $minutos
    // Siempre un rango, nunca un punto: de estimado a estimado × 1.4.
    // Regresa ['min' => int, 'max' => int, 'saturada' => bool, 'pedidos_en_cocina' => int,
    //          'voy_saliendo_disponible' => bool]
    // saturada = max supera tiendas.umbral_espera_min.
    // voy_saliendo_disponible = false si ya hay cupo_bajo_demanda pedidos en espera.
    public function estimarEspera(Tienda $tienda, int $minutos): array
    {
        throw new LogicException('Pendiente: #56');
    }

    // --- Funciones de apoyo ---

    // #105: minutos de cocina ya apartados en la franja que empieza en $franja.
    // Si nadie ha reservado en esa franja todavía, no hay renglón y regresa 0.
    public function minutosOcupados(Tienda $tienda, Carbon $franja): int
    {
        return (int) OcupacionFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('franja_inicio', $franja->format('Y-m-d H:i:s'))
            ->value('minutos_ocupados');
    }

    // T-45: minutos de cocina de los pedidos aceptados o en preparación que aún no están listos.
    // Esqueleto: 0, porque todavía no existe la tabla pedidos. Así #56 funciona desde el primer día.
    public function cargaPendiente(Tienda $tienda): int
    {
        return 0;
    }

    // #171: la fila de capacidad_franjas de esa franja, BLOQUEADA hasta que termine la transacción
    // (->lockForUpdate()). La usa reservar() (#105).
    // Esqueleto: la busca sin bloquear, para que #105 ya pueda llamarla.
    // dia_semana va de 0 (domingo) a 6 (sábado), igual que Carbon::dayOfWeek.
    public function bloquearFranja(Tienda $tienda, Carbon $franja): ?CapacidadFranja
    {
        return CapacidadFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('dia_semana', $franja->dayOfWeek)
            ->where('hora_inicio', $franja->format('H:i:s'))
            ->first();
    }
}
