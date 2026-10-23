<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PerfilResource extends UsuarioResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'facultad' => [
                'id' => $this->facultad->id,
                'nombre' => $this->facultad->nombre,
                'clave' => $this->facultad->clave,
            ],
            'faltas' => 0,
        ];
    }
}
