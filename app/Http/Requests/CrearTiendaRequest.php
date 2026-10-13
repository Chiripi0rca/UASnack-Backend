<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Reglas del alta de tienda. Valida lo que llega ANTES de tocar la base de datos.
class CrearTiendaRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'nombre' => ['required', 'string', 'max:255'],
            'ubicacion' => ['required', 'string', 'max:255'],

            // date_format:H:i obliga a "07:00". Es el mismo formato que devuelve el GET,
            'horario_apertura' => ['required', 'date_format:H:i'],

            // after: evita una tienda que cierre antes de abrir
            'horario_cierre' => ['required', 'date_format:H:i', 'after:horario_apertura'],

            // Encargado: OPCIONAL. Sin él la tienda nace "Sin asignar".
            'id_dueno' => [
                'required',
                'integer',

                // Debe ser un usuario real, con rol de encargado y de la facultad del admin.
                // - exists: evita un error de llave foránea (un 500) con un id inexistente
                // - rol: un alumno o un admin no operan tiendas
                // - id_facultad: un admin no se lleva encargados de otra facultad (aislamiento)
                Rule::exists('usuarios', 'id')
                    ->where('rol', 'encargado_tienda')
                    ->where('id_facultad', $this->user()->id_facultad),

                // Un encargado opera UNA tienda: el middleware tenant y el scope de la #163
                // guardan un solo id de tienda. unique rechaza a quien ya es dueño de otra.
                Rule::unique('tiendas', 'id_dueno'),
            ],
        ];
    }

    // Mensajes en español, para que PWA y Android muestren el mismo texto que el servidor
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'ubicacion.required' => 'La ubicación es obligatoria.',
            'horario_apertura.required' => 'El horario de apertura es obligatorio.',
            'horario_apertura.date_format' => 'El horario de apertura debe tener formato HH:MM.',
            'horario_cierre.required' => 'El horario de cierre es obligatorio.',
            'horario_cierre.date_format' => 'El horario de cierre debe tener formato HH:MM.',
            'horario_cierre.after' => 'El cierre debe ser después de la apertura.',
            'id_dueno.integer' => 'El encargado no es válido.',
            'id_dueno.exists' => 'El encargado no existe o no es de tu facultad.',
            'id_dueno.unique' => 'Ese encargado ya tiene una tienda asignada.',
            'id_dueno.required' => 'Debes asignar un encargado a la tienda.',
        ];
    }
}