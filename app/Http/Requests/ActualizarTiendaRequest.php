<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Valida el PATCH /admin/tiendas/{id}.
// En un PATCH el cliente manda SOLO lo que quiere cambiar,
// por eso todas las reglas empiezan con 'sometimes':
// "si viene el campo, valídalo; si no viene, ignóralo".
class ActualizarTiendaRequest extends FormRequest
{
    // El permiso ya lo revisan los middlewares (auth:sanctum + rol:administrador).
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // El {id} de la URL. 
        $idTienda = $this->route('id');

        return [
            'nombre'           => ['sometimes', 'string', 'max:100'],
            'ubicacion'        => ['sometimes', 'string', 'max:255'],
            'horario_apertura' => ['sometimes', 'date_format:H:i'],
            'horario_cierre'   => ['sometimes', 'date_format:H:i'],
            // Dar de baja o de alta la tienda (true / false).
            'activa'           => ['sometimes', 'boolean'],
            // Cambiar de encargado
            // excepto que ignore() deja pasar al encargado actual de ESTA tienda.
            'id_dueno'         => [
                'sometimes',
                'integer',
                Rule::exists('usuarios', 'id')
                    ->where('rol', 'encargado_tienda')
                    ->where('id_facultad', $this->user()->id_facultad),
                Rule::unique('tiendas', 'id_dueno')->ignore($idTienda),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.string'                => 'El nombre debe ser texto.',
            'nombre.max'                   => 'El nombre no puede pasar de 100 caracteres.',
            'ubicacion.max'                => 'La ubicación no puede pasar de 255 caracteres.',
            'horario_apertura.date_format' => 'El horario de apertura debe tener formato HH:MM.',
            'horario_cierre.date_format'   => 'El horario de cierre debe tener formato HH:MM.',
            'activa.boolean'               => 'El campo activa debe ser true o false.',
            'id_dueno.exists'              => 'El encargado no existe o no es de tu facultad.',
            'id_dueno.unique'              => 'Ese encargado ya tiene una tienda asignada.',
        ];
    }
}