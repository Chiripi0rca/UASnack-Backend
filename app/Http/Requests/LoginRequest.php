<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Form Request del login: valida los datos ANTES de que la petición llegue al controlador.
// Si alguna regla falla, Laravel responde 422 con los errores y AuthController::login ni se ejecuta
class LoginRequest extends FormRequest
{
    // Laravel llama a este método primero para saber si el usuario puede hacer la petición.
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

    // Reglas de validación de lo que llega en el cuerpo (JSON) de la petición.
    public function rules(): array
    {
        return [

            // Debe venir y tener formato de correo, no se valida el dominio (uas.edu.mx)
            // ni si existe en la base: eso lo resuelve el controlador, que responde 401
            // con el mismo mensaje para no revelar qué correos están registrados.
            'correo_institucional' => ['required', 'email'],

            // Debe venir como texto, solo se compara contra el hash guardado, no se crea una contraseña nueva.
            'password' => ['required', 'string'],
        ];
    }
}