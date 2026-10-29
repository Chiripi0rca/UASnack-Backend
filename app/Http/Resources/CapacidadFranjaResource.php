<?php

namespace App\Http\Resources;

use App\Services\CapacidadPanelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Forma de una franja de capacidad en el panel del encargado (#106).
// Lleva los minutos de cocina (lo que se guarda) y su equivalencia en piezas (lo que ve el dueño).
class CapacidadFranjaResource extends JsonResource
{
    public function __construct($resource, private ?float $minutosPorPieza)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'dia_semana' => $this->dia_semana,
            // las columnas time llegan como "11:00:00"; el panel solo necesita "11:00"
            'hora_inicio' => substr($this->hora_inicio, 0, 5),
            'hora_fin' => substr($this->hora_fin, 0, 5),
            'minutos_cocina' => $this->minutos_cocina,
            'piezas' => app(CapacidadPanelService::class)
                ->minutosAPiezas($this->minutos_cocina, $this->minutosPorPieza),
        ];
    }
}
