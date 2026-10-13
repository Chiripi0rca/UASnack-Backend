<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Valida el POST /admin/tiendas.
// La tienda se crea sin encargado; se asigna después por correo (#104).
class CrearTiendaRequest extends FormRequest
{
    // El permiso ya lo revisan los middlewares (auth:sanctum + rol:administrador).
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'           => ['required', 'string', 'max:255'],
            'ubicacion'        => ['required', 'string', 'max:255'],
            'horario_apertura' => ['required', 'date_format:H:i'],
            // after: compara contra el otro campo del mismo request
            'horario_cierre'   => ['required', 'date_format:H:i', 'after:horario_apertura'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'              => 'El nombre es obligatorio.',
            'nombre.max'                   => 'El nombre no puede pasar de 255 caracteres.',
            'ubicacion.required'           => 'La ubicación es obligatoria.',
            'ubicacion.max'                => 'La ubicación no puede pasar de 255 caracteres.',
            'horario_apertura.required'    => 'El horario de apertura es obligatorio.',
            'horario_apertura.date_format' => 'El horario de apertura debe tener formato HH:MM.',
            'horario_cierre.required'      => 'El horario de cierre es obligatorio.',
            'horario_cierre.date_format'   => 'El horario de cierre debe tener formato HH:MM.',
            'horario_cierre.after'         => 'El horario de cierre debe ser después del de apertura.',
        ];
    }
}