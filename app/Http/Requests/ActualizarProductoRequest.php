<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// PATCH /tienda/productos/{id}.
// 'sometimes' porque solo llega lo que se quiere cambiar.
class ActualizarProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre'          => ['sometimes', 'string', 'max:255'],
            'descripcion'     => ['sometimes', 'nullable', 'string'],
            'id_categoria'    => ['sometimes', 'integer', 'exists:categorias,id'],
            'precio'          => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'tiempo_prep_min' => ['sometimes', 'integer', 'min:1'],
            'tiempo_prep_max' => ['sometimes', 'integer', 'min:1'],
            'foto_url'        => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.max'          => 'El nombre no puede pasar de 255 caracteres.',
            'id_categoria.exists' => 'La categoría no existe.',
            'precio.numeric'      => 'El precio debe ser un número.',
            'precio.min'          => 'El precio no puede ser negativo.',
            'precio.max'          => 'El precio no puede pasar de 999,999.99.',
            'tiempo_prep_min.min' => 'El tiempo mínimo de preparación debe ser de al menos 1 minuto.',
            'tiempo_prep_max.min' => 'El tiempo máximo de preparación debe ser de al menos 1 minuto.',
            'foto_url.url'        => 'La foto debe ser una URL válida.',
        ];
    }
}