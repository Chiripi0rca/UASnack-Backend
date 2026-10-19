<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Datos del usuario que salen en la sesión (login y verificar).
// Nunca incluye hash_password, matricula ni telefono.
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'correo_institucional' => $this->correo_institucional,
            'id_facultad' => $this->id_facultad,
            // Se sigue mandando la clave como texto: los clientes no cambian.
            'rol' => $this->claveRol(),
            'reputacion' => $this->reputacion,
            'correo_verificado_en' => $this->correo_verificado_en,
        ];
    }
}