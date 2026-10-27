<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST /tienda/productos/{id}/foto (#238).
// Llega como multipart/form-data con un solo campo: "foto", el archivo de la imagen.
// El id del producto viene en la ruta y la tienda sale del encargado, nunca del cliente.
class SubirFotoProductoRequest extends FormRequest
{
    // Peso máximo en KB (2 MB). Es lo más que acepta el PHP del contenedor por archivo
    // (upload_max_filesize = 2M): una foto más pesada ni siquiera llega a Laravel completa.
    public const PESO_MAXIMO_KB = 2048;

    // Cualquier encargado puede subir fotos; que el producto sea de SU tienda lo revisa el servicio.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Obligatoria, un archivo de verdad, que sea imagen, de tipo JPG, PNG o WebP y de máximo 2 MB.
            'foto' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:'.self::PESO_MAXIMO_KB],
        ];
    }

    // Mensajes en español: son los que el panel muestra debajo del campo.
    public function messages(): array
    {
        return [
            // Sin archivo. También sale si la foto pesa tanto que PHP descarta todo el envío.
            'foto.required' => 'Elige una foto de máximo 2 MB.',
            // El archivo no terminó de subir; casi siempre porque pesa más de lo que PHP acepta.
            'foto.uploaded' => 'La foto no se pudo subir. Debe pesar máximo 2 MB.',
            'foto.file'     => 'Elige una foto de máximo 2 MB.',
            'foto.image'    => 'El archivo debe ser una imagen.',
            'foto.mimes'    => 'La foto debe ser JPG, PNG o WebP.',
            'foto.max'      => 'La foto no puede pesar más de 2 MB.',
        ];
    }
}
