<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\CapacidadFranja;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Capacidad de cocina que el encargado configura desde su panel (#106).
// Se guarda en MINUTOS de cocina (capacidad_franjas.minutos_cocina), que es lo que leen
// franjasDisponibles, reservar y estimarEspera. El dueño la ve y la captura en PERSONAS:
// cuántas personas cocinan pedidos de la app en esa franja. Cada persona aporta la duración
// de la franja como minutos de trabajo: 2 personas en franjas de 10 min = 20 minutos de cocina.
class CapacidadPanelService
{
    // Las franjas de la tienda del encargado, ordenadas, junto con la duración de sus franjas.
    // Regresa ['duracion_franja_min' => int, 'franjas' => Collection<CapacidadFranja>].
    public function capacidadDe(Usuario $encargado): array
    {
        $tienda = $this->tiendaDe($encargado);

        return [
            'duracion_franja_min' => $this->duracionDe($tienda),
            'franjas' => CapacidadFranja::where('id_tienda', $tienda->id)
                ->orderBy('dia_semana')
                ->orderBy('hora_inicio')
                ->get(),
        ];
    }

    // Guarda las franjas que llegan: personas → minutos de cocina. Crea la franja si no existía
    // y actualiza la que ya estaba (la llave es tienda + día + hora). Las demás no se tocan.
    // $franjas: [['dia_semana' => 1, 'hora_inicio' => '11:00', 'personas' => 2], ...]
    public function actualizarCapacidad(Usuario $encargado, array $franjas): array
    {
        $tienda = $this->tiendaDe($encargado);

        $this->validarRejilla($tienda, $franjas);

        $duracion = $this->duracionDe($tienda);

        // Todo o nada: si una franja falla, no queda la capacidad a medias.
        DB::transaction(function () use ($tienda, $franjas, $duracion) {
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
                        'minutos_cocina' => $this->personasAMinutos($franja['personas'], $duracion),
                    ]
                );
            }
        });

        return $this->capacidadDe($encargado);
    }

    // 2 personas × franjas de 10 min = 20 minutos de cocina.
    public function personasAMinutos(int $personas, int $duracion): int
    {
        return $personas * $duracion;
    }

    // Lo contrario, para mostrarle al dueño su capacidad en personas. Normalmente es entero;
    // una capacidad vieja que no cuadre (15 min en franjas de 10) sale con un decimal: 1.5.
    public function minutosAPersonas(int $minutos, int $duracion): int|float
    {
        $personas = round($minutos / $duracion, 1);

        return $personas == (int) $personas ? (int) $personas : $personas;
    }

    public function duracionDe(Tienda $tienda): int
    {
        return max(1, (int) $tienda->duracion_franja_min);
    }

    // Cada hora debe ser una franja real de la tienda: caer en la rejilla que sale de su horario
    // (07:00, 07:10, 07:20...) y terminar a más tardar al cierre. Si no, 422 marcando el renglón.
    private function validarRejilla(Tienda $tienda, array $franjas): void
    {
        $duracion = $this->duracionDe($tienda);
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
