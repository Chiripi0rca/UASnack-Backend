<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST /auth/restablecer-password (HU-33): correo + código + contraseña nueva (dos veces).
class RestablecerPasswordRequest extends FormRequest
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
            'codigo' => ['required', 'digits:6'],
            // "confirmed" pide que llegue también password_confirmation con lo mismo
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'email' => 'Escribe un correo válido.',
            'codigo.digits' => 'El código tiene 6 dígitos.',
            'password.min' => 'Usa al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
