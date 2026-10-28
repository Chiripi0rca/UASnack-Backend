<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // #237: 0 = no se cocina (botanas, refrescos). No ocupa cupo de cocina y la app no muestra tiempo.
            'tiempo_prep_min' => ['required', 'integer', 'min:0'],
            // gte = mayor o igual que el otro campo del mismo request.
            // Si el mínimo es 0 el máximo también: "de 0 a 10 minutos" no tiene sentido.
            'tiempo_prep_max' => [
                'required', 'integer', 'gte:tiempo_prep_min',
                Rule::when($this->filled('tiempo_prep_min') && (int) $this->input('tiempo_prep_min') === 0, ['max:0']),
            ],
            'foto_url'        => ['nullable', 'url', 'max:255'],
            // Opciones propias del producto (HU-04). Las dos listas son opcionales.
            'extras'             => ['sometimes', 'array', 'max:20'],
            'extras.*.nombre'    => ['required', 'string', 'max:100'],
            'extras.*.precio'    => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'quitables'          => ['sometimes', 'array', 'max:20'],
            'quitables.*.nombre' => ['required', 'string', 'max:100'],
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
            'tiempo_prep_min.min'      => 'El tiempo de preparación no puede ser negativo.',
            'tiempo_prep_max.required' => 'El tiempo máximo de preparación es obligatorio.',
            'tiempo_prep_max.gte'      => 'El tiempo máximo no puede ser menor que el mínimo.',
            'tiempo_prep_max.max'      => 'Si el producto no se cocina, el tiempo máximo también va en 0.',
            'foto_url.url'             => 'La foto debe ser una URL válida.',
            'extras.max'               => 'Un producto puede tener máximo 20 extras.',
            'extras.*.nombre.required' => 'Cada extra necesita un nombre.',
            'extras.*.precio.required' => 'Cada extra necesita un precio.',
            'extras.*.precio.min'      => 'El precio de un extra no puede ser negativo.',
            'quitables.max'            => 'Un producto puede tener máximo 20 quitables.',
            'quitables.*.nombre.required' => 'Cada quitable necesita un nombre.',
        ];
    }
}