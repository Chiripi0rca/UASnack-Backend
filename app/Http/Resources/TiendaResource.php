<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Define qué campos de una tienda se le muestran al alumno en el JSON.
// Lo que no aparece aquí (id_cuenta_stripe, id_dueno, parámetros de cocina)
// nunca llega al cliente.
class TiendaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'ubicacion' => $this->ubicacion,

            // La base guarda "07:00:00"; se recorta a "07:00" para mostrarlo limpio
            'horario_apertura' => substr($this->horario_apertura, 0, 5),
            'horario_cierre' => substr($this->horario_cierre, 0, 5),

            // Campo calculado: true/false según la hora actual (ver estaAbierta)
            'abierta' => $this->estaAbierta(),

            // Campo calculado: true si la tienda es de la misma facultad del alumno
            // que pide. El (int) evita que 1 y "1" den false al comparar.
            'es_mi_facultad' => (int) $this->id_facultad === (int) $request->user()->id_facultad,
        ];
    }

    // Decide si la tienda está abierta en este momento
    private function estaAbierta(): bool
    {
        // Una tienda dada de baja nunca está abierta, sin importar la hora
        if (! $this->activa) {
            return false;
        }

        // Hora actual en la zona de Mazatlán
        $ahora = Carbon::now('America/Mazatlan')->format('H:i:s');

        // Abierta si ya pasó la apertura y todavía no llega el cierre.
        // Comparar textos "H:i:s" funciona porque ordenan igual que las horas.
        return $ahora >= $this->horario_apertura && $ahora < $this->horario_cierre;
    }
}