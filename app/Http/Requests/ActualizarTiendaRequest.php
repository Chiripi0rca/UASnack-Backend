<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Valida el PATCH /admin/tiendas/{id}.
// En un PATCH el cliente manda SOLO lo que quiere cambiar,
// por eso todas las reglas empiezan con 'sometimes'.
// El encargado no se cambia aquí: eso es la #104 (por correo).
class ActualizarTiendaRequest extends FormRequest
{
    // El permiso ya lo revisan los middlewares (auth:sanctum + rol:administrador).
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'           => ['sometimes', 'string', 'max:255'],
            'ubicacion'        => ['sometimes', 'string', 'max:255'],
            'horario_apertura' => ['sometimes', 'date_format:H:i'],
            'horario_cierre'   => ['sometimes', 'date_format:H:i'],
            // Dar de baja o de alta la tienda (true / false).
            'activa'           => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.string'                => 'El nombre debe ser texto.',
            'nombre.max'                   => 'El nombre no puede pasar de 255 caracteres.',
            'ubicacion.max'                => 'La ubicación no puede pasar de 255 caracteres.',
            'horario_apertura.date_format' => 'El horario de apertura debe tener formato HH:MM.',
            'horario_cierre.date_format'   => 'El horario de cierre debe tener formato HH:MM.',
            'activa.boolean'               => 'El campo activa debe ser true o false.',
        ];
    }
}