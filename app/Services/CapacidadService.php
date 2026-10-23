<?php

namespace App\Services;

use App\Models\CapacidadFranja;
use App\Models\Tienda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

// ESQUELETO (Sprint 03). Franjas, cupo y tiempo de espera (§7 del documento general).
// Cada método lo completa su tarea:
//   franjasDisponibles → #52  (Ricardo)
//   reservar, liberar  → #105 (Alfredo)
//   estimarEspera      → #56  (José Ángel)
//
// Funciones de apoyo: ya regresan algo para que nadie espere a otro. Quien tiene la tarea
// cambia SOLO el contenido; el nombre y lo que reciben/regresan no se cambian sin avisar.
//   minutosOcupados → #105 (Alfredo).    Hoy regresa 0: todas las franjas se ven vacías.
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
        throw new LogicException('Pendiente: #105');
    }

    // #105: devuelve a la franja los minutos de un pedido cancelado o rechazado.
    public function liberar(Tienda $tienda, Carbon $franja, int $minutos): void
    {
        throw new LogicException('Pendiente: #105');
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
    // Esqueleto: 0. Así #52 funciona desde el primer día, con todas las franjas vacías.
    public function minutosOcupados(Tienda $tienda, Carbon $franja): int
    {
        return 0;
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
