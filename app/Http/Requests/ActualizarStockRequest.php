<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// PATCH /tienda/productos/{id}/stock.
// Mandar 0 = marcar agotado.
class ActualizarStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stock_hoy' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'stock_hoy.required' => 'El stock es obligatorio.',
            'stock_hoy.integer'  => 'El stock debe ser un número entero.',
            'stock_hoy.min'      => 'El stock no puede ser negativo.',
        ];
    }
}