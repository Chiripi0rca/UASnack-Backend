<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// POST /auth/cambiar-password (HU-33, con sesión): contraseña actual + nueva (dos veces).
// Que la actual sea correcta lo revisa el servicio (PASSWORD_ACTUAL_INCORRECTA).
class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_actual' => ['required', 'string'],
            // "different": cambiarla por la misma no tiene sentido
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:password_actual'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'password.min' => 'Usa al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.different' => 'La contraseña nueva debe ser distinta de la actual.',
        ];
    }
}
