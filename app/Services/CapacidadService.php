<?php

namespace App\Services;

use App\Enums\EstadoDescuento;
use App\Enums\EstadoPedido;
use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\Descuento;
use App\Models\OcupacionFranja;
use App\Models\Pedido;
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
//   cargaPendiente  → T-45 (Alfredo).    Suma los minutos de los pedidos ACEPTADO y EN_PREPARACION.
//   bloquearFranja  → #171 (José Ángel). Hoy busca la franja SIN bloquearla.
class CapacidadService
{
    // Hora de las tiendas (Culiacán). Las franjas, capacidad_franjas y ocupacion_franjas se guardan
    // en hora local "de pared" (11:00 es 11:00 en la tienda), aunque la app corra en UTC.
    public const ZONA_HORARIA = 'America/Mazatlan';

    // #52: las próximas franjas de la tienda a partir de $fecha, generadas desde su horario
    // (horario_apertura a horario_cierre, cada duracion_franja_min). Solo las del mismo día.
    // Marca llena (disponible = false) la franja donde lo que queda no alcanza para $minutos,
    // que son los minutos de cocina del pedido que se está armando.
    // Regresa como máximo $limite franjas, nunca las 72 del día.
    // Lo que queda en cada franja = minutos_cocina de capacidad_franjas - minutosOcupados().
    //
    // Cada franja: ['fecha', 'inicio', 'fin', 'minutos_restantes', 'disponible', 'descuento'],
    // la forma acordada para GET /tiendas/{id}/franjas (#53).
    //
    // Mismas reglas que reservar() (#105), para que lo que aquí sale disponible sí se pueda apartar:
    //   - una franja sin renglón en capacidad_franjas no tiene cupo (sale llena);
    //   - un pedido de 0 minutos (#237, no se cocina) cabe en cualquier franja.
    // Si la tienda no tiene capacidad ningún bloque de ese día, ese día no opera: regresa vacío.
    public function franjasDisponibles(Tienda $tienda, Carbon $fecha, int $minutos, int $limite = 8): Collection
    {
        $ahora = $fecha->copy()->setTimezone(self::ZONA_HORARIA);
        $dia = $ahora->format('Y-m-d');

        // Capacidad de cada franja de ese día de la semana, por hora de inicio ("11:00:00" => 24)
        $capacidades = CapacidadFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('dia_semana', $ahora->dayOfWeek)
            ->pluck('minutos_cocina', 'hora_inicio')
            ->mapWithKeys(fn ($minutosCocina, $hora) => [substr($hora, 0, 5) => (int) $minutosCocina]);

        if ($capacidades->isEmpty()) {
            return collect();
        }

        $descuentos = $this->descuentosDelDia($tienda, $ahora);
        $duracion = max(1, (int) $tienda->duracion_franja_min);
        $cierre = Carbon::parse("$dia {$tienda->horario_cierre}", self::ZONA_HORARIA);
        $inicio = Carbon::parse("$dia {$tienda->horario_apertura}", self::ZONA_HORARIA);

        $franjas = collect();

        // La rejilla sale del horario: 07:00, 07:10, 07:20... La última termina a más tardar al cierre.
        // Solo las que todavía no empiezan: la que ya está corriendo no da tiempo de cocinar.
        for (; $inicio->copy()->addMinutes($duracion)->lte($cierre) && $franjas->count() < $limite; $inicio->addMinutes($duracion)) {
            if ($inicio->lte($ahora)) {
                continue;
            }

            $hora = $inicio->format('H:i');
            $capacidad = $capacidades->get($hora);
            $restantes = $capacidad === null
                ? 0
                : max(0, $capacidad - $this->minutosOcupados($tienda, $inicio));

            $franjas->push([
                'fecha' => $dia,
                'inicio' => $hora,
                'fin' => $inicio->copy()->addMinutes($duracion)->format('H:i'),
                'minutos_restantes' => $restantes,
                'disponible' => $minutos <= 0 || ($capacidad !== null && $restantes >= $minutos),
                'descuento' => $this->descuentoDeLaFranja($descuentos, $hora),
            ]);
        }

        return $franjas;
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
    // Los que siguen EN_ESPERA (voy saliendo sin pulsar) no cuentan: todavía no entran a cocina.
    public function cargaPendiente(Tienda $tienda): int
    {
        return (int) Pedido::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->whereIn('estado', [EstadoPedido::Aceptado->value, EstadoPedido::EnPreparacion->value])
            ->sum('minutos_cocina');
    }

    // #52: descuentos de la tienda que valen ese día: activos, vigentes y para ese día de la semana.
    // Se piden una sola vez y descuentoDeLaFranja() los revisa hora por hora.
    private function descuentosDelDia(Tienda $tienda, Carbon $ahora): Collection
    {
        $dia = $ahora->format('Y-m-d');

        return Descuento::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('activo', EstadoDescuento::Activo->value)
            ->whereDate('vigente_desde', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $dia))
            ->get()
            ->filter(fn (Descuento $d) => in_array($ahora->dayOfWeek, json_decode($d->dias_semana, true) ?? [], true));
    }

    // #52: el porcentaje con el que se marca la franja ("11:00–11:10 · −10%"), o null.
    // Si hay varios, el mayor: nunca se acumulan (§13.5).
    private function descuentoDeLaFranja(Collection $descuentos, string $hora): ?int
    {
        $porcentaje = $descuentos
            ->filter(fn (Descuento $d) => substr($d->hora_inicio, 0, 5) <= $hora && $hora < substr($d->hora_fin, 0, 5))
            ->max('porcentaje');

        return $porcentaje === null ? null : (int) $porcentaje;
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