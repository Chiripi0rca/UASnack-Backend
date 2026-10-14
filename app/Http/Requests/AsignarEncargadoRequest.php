<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AsignarEncargadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso de administrador lo controla el middleware de la ruta.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'correo_institucional' => strtolower(
                trim((string) $this->input('correo_institucional', ''))
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'correo_institucional' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'correo_institucional.required' =>
                'El correo institucional es obligatorio.',
            'correo_institucional.email' =>
                'Escribe un correo institucional válido.',
        ];
    }
}