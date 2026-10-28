<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// GET /tiendas/{id}/espera?minutos=18 (#57).
// minutos = minutos de cocina del carrito (tiempo_prep_min × cantidad de cada producto).
// 0 es válido: un carrito de cosas que no se cocinan (#237) solo espera la carga que ya hay.
class EsperaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'minutos' => ['required', 'integer', 'min:0', 'max:600'],
        ];
    }

    public function messages(): array
    {
        return [
            'minutos.required' => 'Indica los minutos de cocina del pedido.',
            'minutos.integer' => 'Los minutos deben ser un número entero.',
            'minutos.min' => 'Los minutos no pueden ser negativos.',
            'minutos.max' => 'Los minutos no pueden pasar de 600.',
        ];
    }
}
