<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// GET /tiendas/{id}/franjas?minutos=18&limite=8 (#53).
// minutos = minutos de cocina del carrito (tiempo_prep_min × cantidad de cada producto).
// 0 es válido: un carrito de cosas que no se cocinan (#237) cabe en cualquier franja.
class FranjasRequest extends FormRequest
{
    // Nunca más de 8: la app no muestra las 72 franjas del día (§7.1).
    public const LIMITE_MAXIMO = 8;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'minutos' => ['required', 'integer', 'min:0', 'max:600'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.self::LIMITE_MAXIMO],
        ];
    }

    public function messages(): array
    {
        return [
            'minutos.required' => 'Indica los minutos de cocina del pedido.',
            'minutos.integer' => 'Los minutos deben ser un número entero.',
            'minutos.min' => 'Los minutos no pueden ser negativos.',
            'minutos.max' => 'Los minutos no pueden pasar de 600.',
            'limite.integer' => 'El límite debe ser un número entero.',
            'limite.min' => 'El límite debe ser al menos 1.',
            'limite.max' => 'El límite no puede pasar de '.self::LIMITE_MAXIMO.'.',
        ];
    }
}
