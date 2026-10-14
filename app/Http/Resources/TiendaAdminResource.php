<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Qué ve el ADMINISTRADOR de cada tienda. Lleva más campos que TiendaResource (el del alumno).
// Sigue sin exponer id_cuenta_stripe ni cuenta_habilitada: eso es del panel de la tienda.
class TiendaAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'ubicacion' => $this->ubicacion,

            // La base guarda "07:00:00"; se recorta a "07:00"
            'horario_apertura' => substr($this->horario_apertura, 0, 5),
            'horario_cierre' => substr($this->horario_cierre, 0, 5),

            // Booleano: el admin ve también las de baja
            'activa' => $this->activa,

            // Facultad: el id y el nombre. ?-> evita error si no tuviera facultad.
            'id_facultad' => $this->id_facultad,
            'facultad' => $this->facultad?->nombre,

            // Encargado actual, o null si no tiene. Solo lo necesario para identificarlo.
            'encargado' => $this->dueno ? [
                'id' => $this->dueno->id,
                'nombre' => trim($this->dueno->nombre . ' ' . $this->dueno->apellido),
                'correo' => $this->dueno->correo_institucional,
            ] : null,
        ];
    }
}