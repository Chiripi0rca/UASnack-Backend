<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// PUT /tienda/capacidad (#106).
// El dueño contesta en PIEZAS por franja; el servidor las convierte a minutos de cocina.
// Cada renglón es una franja: día de la semana (0 = domingo a 6 = sábado), su hora de inicio y las piezas.
// 0 piezas es válido: la tienda no cocina en esa franja.
class ActualizarCapacidadRequest extends FormRequest
{
    // 7 días × 72 franjas de 10 minutos en un horario de 07:00 a 19:00 son 504; con 600 sobra.
    public const MAXIMO_FRANJAS = 600;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'franjas' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO_FRANJAS],
            'franjas.*.dia_semana' => ['required', 'integer', 'between:0,6'],
            'franjas.*.hora_inicio' => ['required', 'date_format:H:i'],
            'franjas.*.piezas' => ['required', 'integer', 'min:0', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'franjas.required' => 'Indica las franjas que quieres cambiar.',
            'franjas.array' => 'Las franjas deben ser una lista.',
            'franjas.min' => 'Indica al menos una franja.',
            'franjas.max' => 'No puedes cambiar más de '.self::MAXIMO_FRANJAS.' franjas a la vez.',
            'franjas.*.dia_semana.required' => 'El día de la semana es obligatorio.',
            'franjas.*.dia_semana.integer' => 'El día de la semana debe ser un número del 0 al 6.',
            'franjas.*.dia_semana.between' => 'El día de la semana debe ir de 0 (domingo) a 6 (sábado).',
            'franjas.*.hora_inicio.required' => 'La hora de inicio es obligatoria.',
            'franjas.*.hora_inicio.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'franjas.*.piezas.required' => 'Las piezas son obligatorias.',
            'franjas.*.piezas.integer' => 'Las piezas deben ser un número entero.',
            'franjas.*.piezas.min' => 'Las piezas no pueden ser negativas.',
            'franjas.*.piezas.max' => 'Las piezas no pueden pasar de 500.',
        ];
    }
}
