<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Capacidad de cocina que el encargado configura desde su panel (#106).
// Se guarda en MINUTOS de cocina (capacidad_franjas.minutos_cocina), que es lo que leen
// franjasDisponibles, reservar y estimarEspera; el dueño la ve y la captura en PIEZAS.
class CapacidadPanelService
{
    // Las franjas de la tienda del encargado, ordenadas, junto con lo que vale una pieza.
    // Regresa ['minutos_por_pieza' => ?float, 'franjas' => Collection<CapacidadFranja>].
    public function capacidadDe(Usuario $encargado): array
    {
        $tienda = $this->tiendaDe($encargado);

        return [
            'minutos_por_pieza' => $this->minutosPorPieza($tienda),
            'franjas' => CapacidadFranja::where('id_tienda', $tienda->id)
                ->orderBy('dia_semana')
                ->orderBy('hora_inicio')
                ->get(),
        ];
    }

    // Guarda las franjas que llegan: piezas → minutos de cocina. Crea la franja si no existía
    // y actualiza la que ya estaba (la llave es tienda + día + hora). Las demás no se tocan.
    // $franjas: [['dia_semana' => 1, 'hora_inicio' => '11:00', 'piezas' => 5], ...]
    public function actualizarCapacidad(Usuario $encargado, array $franjas): array
    {
        $tienda = $this->tiendaDe($encargado);
        $minutosPorPieza = $this->minutosPorPieza($tienda);

        if ($minutosPorPieza === null) {
            throw new ErrorNegocio(
                'SIN_PRODUCTOS_QUE_SE_COCINEN',
                'Agrega productos que se preparen para poder medir tu capacidad en piezas.',
                422
            );
        }

        $this->validarRejilla($tienda, $franjas);

        $duracion = max(1, (int) $tienda->duracion_franja_min);

        // Todo o nada: si una franja falla, no queda la capacidad a medias.
        DB::transaction(function () use ($tienda, $franjas, $minutosPorPieza, $duracion) {
            foreach ($franjas as $franja) {
                $inicio = $this->aMinutos($franja['hora_inicio']);

                CapacidadFranja::updateOrCreate(
                    [
                        'id_tienda' => $tienda->id,
                        'dia_semana' => $franja['dia_semana'],
                        'hora_inicio' => $this->aHora($inicio),
                    ],
                    [
                        'hora_fin' => $this->aHora($inicio + $duracion),
                        'minutos_cocina' => $this->piezasAMinutos($franja['piezas'], $minutosPorPieza),
                    ]
                );
            }
        });

        return $this->capacidadDe($encargado);
    }

    // Cuántos minutos de cocina vale una pieza en esta tienda: el promedio de tiempo_prep_min
    // de sus productos que sí se cocinan (los de tiempo 0, #237, no cuentan).
    // null si la tienda no tiene ninguno todavía. Con 2 decimales, igual que lo ve el panel.
    public function minutosPorPieza(Tienda $tienda): ?float
    {
        $promedio = Producto::where('id_tienda', $tienda->id)
            ->where('tiempo_prep_min', '>', 0)
            ->avg('tiempo_prep_min');

        return $promedio === null ? null : round((float) $promedio, 2);
    }

    // 5 piezas × 3.0 min = 15 minutos de cocina.
    public function piezasAMinutos(int $piezas, float $minutosPorPieza): int
    {
        return (int) round($piezas * $minutosPorPieza);
    }

    // Lo contrario, para mostrarle al dueño su capacidad en piezas. null si no hay con qué medir.
    public function minutosAPiezas(int $minutos, ?float $minutosPorPieza): ?int
    {
        return $minutosPorPieza ? (int) round($minutos / $minutosPorPieza) : null;
    }

    // Cada hora debe ser una franja real de la tienda: caer en la rejilla que sale de su horario
    // (07:00, 07:10, 07:20...) y terminar a más tardar al cierre. Si no, 422 marcando el renglón.
    private function validarRejilla(Tienda $tienda, array $franjas): void
    {
        $duracion = max(1, (int) $tienda->duracion_franja_min);
        $apertura = $this->aMinutos($tienda->horario_apertura);
        $cierre = $this->aMinutos($tienda->horario_cierre);
        $errores = [];

        foreach ($franjas as $i => $franja) {
            $inicio = $this->aMinutos($franja['hora_inicio']);

            $enLaRejilla = $inicio >= $apertura
                && ($inicio - $apertura) % $duracion === 0
                && $inicio + $duracion <= $cierre;

            if (! $enLaRejilla) {
                $errores["franjas.$i.hora_inicio"] = ['Esa hora no es una franja de tu horario.'];
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    // "11:10" o "11:10:00" → 670 minutos desde la medianoche.
    private function aMinutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    // 670 → "11:10:00", el formato de las columnas time.
    private function aHora(int $minutos): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutos, 60), $minutos % 60);
    }

    // La tienda del encargado, leída de la base (con sus horarios y la duración de franja).
    private function tiendaDe(Usuario $encargado): Tienda
    {
        $tienda = Tienda::where('id_dueno', $encargado->id)->first();

        if (! $tienda) {
            throw new ErrorNegocio('SIN_TIENDA_ASIGNADA', 'No tienes una tienda asignada.', 403);
        }

        return $tienda;
    }
}
