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

// ESQUELETO (Sprint 03). Franjas, cupo y tiempo de espera (§7 del documento general).
// Cada método lo completa su tarea:
//   franjasDisponibles → #52  (Ricardo). La franja es la hora de RECOGER; se cocina en las de antes.
//   reservar, liberar  → #105 (Alfredo). Reparten el pedido en las franjas anteriores a la recogida.
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

    // #52: a qué horas puede pasar el alumno por su pedido, a partir de $fecha. Solo las del mismo día.
    //
    // La franja que elige el alumno es la hora de RECOGER: el pedido se cocina en las franjas
    // ANTERIORES que todavía no empiezan (la más cercana a la recogida primero, ver reservar()).
    // Por eso una hora para recoger está disponible si la cocina que queda en las franjas de antes
    // alcanza para los $minutos del pedido. Consecuencias:
    //   - a las 11:09 la franja de las 11:10 no se ofrece para algo que se cocina: no hay antes
    //     ninguna franja sin empezar donde cocinarlo; la primera es la de las 11:20;
    //   - la primera franja del día (07:00) nunca se ofrece para algo que se cocina;
    //   - un pedido grande encuentra lugar más tarde, cuando hay más franjas antes para cocinarlo;
    //   - un pedido de 0 minutos (#237, no se cocina) se puede recoger en cualquier franja.
    // Regresa como máximo $limite franjas, nunca las 72 del día. Si en las primeras $limite no cabe
    // el pedido pero más tarde sí, la lista empieza en la primera donde cabe.
    // Si la tienda no tiene capacidad ningún bloque de ese día, ese día no opera: regresa vacío.
    //
    // Cada franja: ['fecha', 'inicio', 'fin', 'minutos_restantes', 'disponible', 'descuento'],
    // la forma acordada para GET /tiendas/{id}/franjas (#53). minutos_restantes = la cocina que
    // queda antes de esa hora de recogida.
    public function franjasDisponibles(Tienda $tienda, Carbon $fecha, int $minutos, int $limite = 8): Collection
    {
        $ahora = $fecha->copy()->setTimezone(self::ZONA_HORARIA);
        $rejilla = $this->rejillaDelDia($tienda, $ahora);

        if ($rejilla->isEmpty()) {
            return collect();
        }

        $descuentos = $this->descuentosDelDia($tienda, $ahora);

        $horas = $rejilla
            ->filter(fn (array $franja) => ! $franja['empezo'])
            ->map(fn (array $franja) => [
                'fecha' => $franja['inicio']->format('Y-m-d'),
                'inicio' => $franja['inicio']->format('H:i'),
                'fin' => $franja['fin']->format('H:i'),
                'minutos_restantes' => $franja['cocina_antes'],
                'disponible' => $minutos <= 0 || $franja['cocina_antes'] >= $minutos,
                'descuento' => $this->descuentoDeLaFranja($descuentos, $franja['inicio']->format('H:i')),
            ])
            ->values();

        $primeraQueSirve = $horas->search(fn (array $franja) => $franja['disponible']);
        $desde = $primeraQueSirve !== false && $primeraQueSirve >= $limite ? $primeraQueSirve : 0;

        return $horas->slice($desde, $limite)->values();
    }

    // #53: el pedido más grande, en minutos de cocina, que todavía cabe hoy en alguna hora de recogida.
    // Es la cocina que queda antes de la última franja del día. 0 si la tienda ya no cocina hoy.
    // Con esto la app le dice al alumno "tu pedido necesita 45 min y hoy solo caben hasta 30".
    public function minutosMaximosHoy(Tienda $tienda, Carbon $fecha): int
    {
        $ahora = $fecha->copy()->setTimezone(self::ZONA_HORARIA);

        return (int) $this->rejillaDelDia($tienda, $ahora)
            ->filter(fn (array $franja) => ! $franja['empezo'])
            ->max('cocina_antes');
    }

    // #105: aparta $minutos de cocina para un pedido que se RECOGE en la franja que empieza en $recogida.
    // Se cocina en las franjas anteriores que todavía no empiezan: primero llena la más cercana a la
    // recogida y lo que falte lo toma de las de antes (un pedido grande se reparte en varias).
    // Regresa el reparto, para guardarlo en pedidos.reparto_cocina y poder devolverlo con liberar():
    //   [['franja_inicio' => '2026-10-26 11:10:00', 'minutos' => 20], ['franja_inicio' => '... 11:00:00', 'minutos' => 5]]
    // Si no alcanza, lanza ErrorNegocio('FRANJA_LLENA', ..., 409) y no aparta nada.
    // Va dentro de DB::transaction() y bloquea cada franja con bloquearFranja() (#171), siempre de la
    // más temprana a la más tardía para que dos reservas nunca se esperen en orden cruzado.
    public function reservar(Tienda $tienda, Carbon $recogida, int $minutos): array
    {
        // Un pedido que no se cocina (#237, tiempo 0) no ocupa cupo.
        if ($minutos <= 0) {
            return [];
        }

        // Si quien llama ya abrió una transacción (PedidoService::crear), esta se vuelve un
        // savepoint dentro de ella y los bloqueos duran hasta que termine la de afuera.
        return DB::transaction(function () use ($tienda, $recogida, $minutos) {
            $libres = [];
            foreach ($this->franjasParaCocinar($tienda, $recogida) as $franja) {
                $capacidad = $this->bloquearFranja($tienda, $franja);

                // Sin renglón de capacidad, la tienda no cocina en esa franja.
                $libres[$franja->format('Y-m-d H:i:s')] = $capacidad
                    ? max(0, (int) $capacidad->minutos_cocina - $this->minutosOcupados($tienda, $franja))
                    : 0;
            }

            if (array_sum($libres) < $minutos) {
                throw new ErrorNegocio('FRANJA_LLENA', 'Esa hora ya se llenó. Elige otra.', 409);
            }

            // De la franja más cercana a la recogida hacia atrás
            $reparto = [];
            $falta = $minutos;
            foreach (array_reverse($libres, true) as $franjaInicio => $disponibles) {
                if ($falta === 0) {
                    break;
                }

                $toma = min($falta, $disponibles);
                if ($toma === 0) {
                    continue;
                }

                $ocupacion = OcupacionFranja::withoutGlobalScopes()->firstOrCreate(
                    ['id_tienda' => $tienda->id, 'franja_inicio' => $franjaInicio],
                    ['minutos_ocupados' => 0],
                );
                $ocupacion->increment('minutos_ocupados', $toma);

                $reparto[] = ['franja_inicio' => $franjaInicio, 'minutos' => $toma];
                $falta -= $toma;
            }

            return $reparto;
        });
    }

    // #105: devuelve a sus franjas los minutos de un pedido cancelado o rechazado.
    // $reparto es justo lo que regresó reservar() (guardado en pedidos.reparto_cocina).
    public function liberar(Tienda $tienda, array $reparto): void
    {
        if ($reparto === []) {
            return;
        }

        // Mismo orden de bloqueo que reservar(): de la franja más temprana a la más tardía.
        usort($reparto, fn (array $a, array $b) => strcmp($a['franja_inicio'], $b['franja_inicio']));

        DB::transaction(function () use ($tienda, $reparto) {
            foreach ($reparto as $parte) {
                $franja = Carbon::parse($parte['franja_inicio']);
                $this->bloquearFranja($tienda, $franja);

                $ocupacion = OcupacionFranja::withoutGlobalScopes()
                    ->where('id_tienda', $tienda->id)
                    ->where('franja_inicio', $franja->format('Y-m-d H:i:s'))
                    ->first();

                if (! $ocupacion) {
                    continue;
                }

                // Nunca por debajo de 0.
                $ocupacion->minutos_ocupados = max(0, $ocupacion->minutos_ocupados - (int) $parte['minutos']);
                $ocupacion->save();
            }
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
        // carga_pendiente / capacidad + mi_pedido (§7.4)
        $estimado = $this->cargaPendiente($tienda) / $this->capacidadPorMinuto($tienda) + max(0, $minutos);

        // Siempre un rango: de estimado a estimado × 1.4. Si es muy chico, el máximo
        // le gana al mínimo por 1 para que nunca salga un punto (16 → 16–22).
        $min = (int) round($estimado);
        $max = max((int) round($estimado * 1.4), $min + 1);

        $enCocina = Pedido::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->whereIn('estado', [EstadoPedido::Aceptado->value, EstadoPedido::EnPreparacion->value])
            ->count();

        $enEspera = Pedido::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('estado', EstadoPedido::EnEspera->value)
            ->count();

        return [
            'min' => $min,
            'max' => $max,
            'saturada' => $max > $tienda->umbral_espera_min,
            'pedidos_en_cocina' => $enCocina,
            'voy_saliendo_disponible' => $enEspera < $tienda->cupo_bajo_demanda,
        ];
    }

    // #56: minutos de cocina que la tienda saca por cada minuto real, con la franja de ahorita:
    // minutos_cocina de capacidad_franjas / duracion_franja_min (10 min de cocina en franjas de
    // 10 min = 1.0). Si no hay renglón para esta hora, usa 1.0, que además evita dividir entre 0.
    private function capacidadPorMinuto(Tienda $tienda): float
    {
        $ahora = Carbon::now(self::ZONA_HORARIA);

        $minutosCocina = CapacidadFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('dia_semana', $ahora->dayOfWeek)
            ->where('hora_inicio', '<=', $ahora->format('H:i:s'))
            ->where('hora_fin', '>', $ahora->format('H:i:s'))
            ->value('minutos_cocina');

        if (! $minutosCocina) {
            return 1.0;
        }

        return $minutosCocina / max(1, (int) $tienda->duracion_franja_min);
    }

    // --- Funciones de apoyo ---

    // La rejilla del día de $ahora, de la apertura al cierre, con lo que necesita franjasDisponibles():
    //   inicio, fin    → Carbon en hora de la tienda
    //   empezo         → la franja ya empezó: ni se ofrece para recoger ni se cocina en ella
    //   libres         → minutos de cocina que le quedan (0 si ya empezó o no tiene capacidad)
    //   cocina_antes   → suma de "libres" de todas las franjas anteriores: la cocina disponible
    //                    para un pedido que se recoge en esta franja
    // Vacía si la tienda no tiene capacidad ningún bloque de ese día.
    private function rejillaDelDia(Tienda $tienda, Carbon $ahora): Collection
    {
        $dia = $ahora->format('Y-m-d');

        // Capacidad de cada franja de ese día de la semana, por hora de inicio ("11:00" => 20)
        $capacidades = CapacidadFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('dia_semana', $ahora->dayOfWeek)
            ->pluck('minutos_cocina', 'hora_inicio')
            ->mapWithKeys(fn ($minutosCocina, $hora) => [substr($hora, 0, 5) => (int) $minutosCocina]);

        if ($capacidades->isEmpty()) {
            return collect();
        }

        // Lo ya apartado ese día, en una sola consulta ("11:00" => 12)
        $ocupados = OcupacionFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->whereDate('franja_inicio', $dia)
            ->pluck('minutos_ocupados', 'franja_inicio')
            ->mapWithKeys(fn ($minutosOcupados, $inicio) => [substr($inicio, 11, 5) => (int) $minutosOcupados]);

        $duracion = max(1, (int) $tienda->duracion_franja_min);
        $cierre = Carbon::parse("$dia {$tienda->horario_cierre}", self::ZONA_HORARIA);

        $rejilla = collect();
        $cocinaAntes = 0;

        // 07:00, 07:10, 07:20... La última termina a más tardar al cierre.
        for ($inicio = Carbon::parse("$dia {$tienda->horario_apertura}", self::ZONA_HORARIA);
            $inicio->copy()->addMinutes($duracion)->lte($cierre);
            $inicio->addMinutes($duracion)) {
            $hora = $inicio->format('H:i');
            $empezo = $inicio->lte($ahora);
            $capacidad = $capacidades->get($hora);
            $libres = $empezo || $capacidad === null ? 0 : max(0, $capacidad - $ocupados->get($hora, 0));

            $rejilla->push([
                'inicio' => $inicio->copy(),
                'fin' => $inicio->copy()->addMinutes($duracion),
                'empezo' => $empezo,
                'libres' => $libres,
                'cocina_antes' => $cocinaAntes,
            ]);

            $cocinaAntes += $libres;
        }

        return $rejilla;
    }

    // Las franjas donde se puede cocinar un pedido que se recoge en $recogida: las de ese mismo día
    // que todavía no empiezan y que terminan a más tardar cuando empieza la recogida.
    // De la más temprana a la más tardía. $recogida está en hora de la tienda ("de pared").
    private function franjasParaCocinar(Tienda $tienda, Carbon $recogida): array
    {
        $duracion = max(1, (int) $tienda->duracion_franja_min);
        $dia = $recogida->format('Y-m-d');
        $ahora = Carbon::now(self::ZONA_HORARIA)->format('Y-m-d H:i:s');
        $apertura = Carbon::parse("$dia {$tienda->horario_apertura}");

        $franjas = [];
        for ($inicio = Carbon::parse($recogida->format('Y-m-d H:i:s'))->subMinutes($duracion);
            $inicio->gte($apertura) && $inicio->format('Y-m-d H:i:s') > $ahora;
            $inicio->subMinutes($duracion)) {
            array_unshift($franjas, $inicio->copy());
        }

        return $franjas;
    }

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
    // (->lockForUpdate()). La usan reservar() y liberar() (#105).
    // El bloqueo solo dura mientras haya una transacción abierta: quien llame debe estar dentro de
    // DB::transaction(). Una segunda petición sobre la misma franja espera aquí hasta que la primera
    // termine, y entonces lee los minutos ya actualizados: por eso no se toma el último cupo dos veces.
    // Si la franja no tiene renglón no hay nada que bloquear y regresa null (reservar() la trata como llena).
    // dia_semana va de 0 (domingo) a 6 (sábado), igual que Carbon::dayOfWeek.
    public function bloquearFranja(Tienda $tienda, Carbon $franja): ?CapacidadFranja
    {
        return CapacidadFranja::withoutGlobalScopes()
            ->where('id_tienda', $tienda->id)
            ->where('dia_semana', $franja->dayOfWeek)
            ->where('hora_inicio', $franja->format('H:i:s'))
            ->lockForUpdate()
            ->first();
    }
}