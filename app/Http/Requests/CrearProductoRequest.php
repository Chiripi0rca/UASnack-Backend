<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST /tienda/productos.
// id_tienda NO va aquí: sale del encargado autenticado, nunca del cliente.
// estado_producto tampoco: el servidor lo decide según el stock.
class CrearProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'          => ['required', 'string', 'max:255'],
            'descripcion'     => ['nullable', 'string'],
            'id_categoria'    => ['required', 'integer', 'exists:categorias,id'],
            // numeric(8,2) en la BD: máximo 999,999.99
            'precio'          => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'stock_hoy'       => ['required', 'integer', 'min:0'],
            'tiempo_prep_min' => ['required', 'integer', 'min:1'],
            // gte = mayor o igual que el otro campo del mismo request
            'tiempo_prep_max' => ['required', 'integer', 'gte:tiempo_prep_min'],
            'foto_url'        => ['nullable', 'url', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'          => 'El nombre es obligatorio.',
            'nombre.max'               => 'El nombre no puede pasar de 255 caracteres.',
            'id_categoria.required'    => 'La categoría es obligatoria.',
            'id_categoria.exists'      => 'La categoría no existe.',
            'precio.required'          => 'El precio es obligatorio.',
            'precio.numeric'           => 'El precio debe ser un número.',
            'precio.min'               => 'El precio no puede ser negativo.',
            'precio.max'               => 'El precio no puede pasar de 999,999.99.',
            'stock_hoy.required'       => 'El stock es obligatorio.',
            'stock_hoy.integer'        => 'El stock debe ser un número entero.',
            'stock_hoy.min'            => 'El stock no puede ser negativo.',
            'tiempo_prep_min.required' => 'El tiempo mínimo de preparación es obligatorio.',
            'tiempo_prep_min.min'      => 'El tiempo mínimo de preparación debe ser de al menos 1 minuto.',
            'tiempo_prep_max.required' => 'El tiempo máximo de preparación es obligatorio.',
            'tiempo_prep_max.gte'      => 'El tiempo máximo no puede ser menor que el mínimo.',
            'foto_url.url'             => 'La foto debe ser una URL válida.',
        ];
    }
}