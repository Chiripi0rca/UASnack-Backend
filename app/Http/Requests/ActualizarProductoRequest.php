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
            // Si llega una lista, REEMPLAZA a la anterior completa; si no llega, no se toca.
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
            'nombre.max'          => 'El nombre no puede pasar de 255 caracteres.',
            'id_categoria.exists' => 'La categoría no existe.',
            'precio.numeric'      => 'El precio debe ser un número.',
            'precio.min'          => 'El precio no puede ser negativo.',
            'precio.max'          => 'El precio no puede pasar de 999,999.99.',
            'tiempo_prep_min.min' => 'El tiempo mínimo de preparación debe ser de al menos 1 minuto.',
            'tiempo_prep_max.min' => 'El tiempo máximo de preparación debe ser de al menos 1 minuto.',
            'foto_url.url'        => 'La foto debe ser una URL válida.',
            'extras.max'               => 'Un producto puede tener máximo 20 extras.',
            'extras.*.nombre.required' => 'Cada extra necesita un nombre.',
            'extras.*.precio.required' => 'Cada extra necesita un precio.',
            'extras.*.precio.min'      => 'El precio de un extra no puede ser negativo.',
            'quitables.max'            => 'Un producto puede tener máximo 20 quitables.',
            'quitables.*.nombre.required' => 'Cada quitable necesita un nombre.',
        ];
    }
}