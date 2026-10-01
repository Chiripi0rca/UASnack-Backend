<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerificarCorreoRequest extends FormRequest
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
        ];
    }
}