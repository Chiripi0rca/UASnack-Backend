<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST /auth/olvide-password (HU-33). Solo se valida el formato del correo:
// si existe o no lo decide el servicio, sin revelarlo.
class OlvidePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'correo_institucional' => strtolower($this->correo_institucional ?? ''),
        ]);
    }

    public function rules(): array
    {
        return [
            'correo_institucional' => ['required', 'email'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'email' => 'Escribe un correo válido.',
        ];
    }
}
