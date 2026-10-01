<?php

namespace App\Http\Requests;

use App\Rules\CorreoInstitucionalValido;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarUsuarioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Laravel llama esta funcion primero antes de registrarse si el usuario esta autorizado para hacer la peticion,
        // en este caso siempre va a ser true porque cualquier usuario puede registrarse
    }
    protected function prepareForValidation(): void
{
    $this->merge([
        'correo_institucional' => strtolower($this->correo_institucional ?? ''),
    ]);
}

    public function rules(): array // regrasa una arreglo asociativo
    {
        return [
            // aqui le decimos a Eloquent que campos se pueden llenar y cuales no,
            // tambien se establecen las validaciones de cada campo
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'correo_institucional' => ['required', 'string', 'email',
                'max:255', 'unique:usuarios,correo_institucional', new CorreoInstitucionalValido], // aqui le dicimos que vata primero a la DB usaurio y vea si ese correo ya existe
            // si el correo no existe le pasamos una una instancia para verficiar que el correo sea valido ya que debe de terminar con (uas.edu.mx)
            'id_facultad' => ['required', 'integer', 'exists:facultades,id'], // aqui verificamos si la facultad existe
            'password' => ['required', 'string', 'min:8'],
            'telefono' => ['required', 'digits:10'],
            'acepto_terminos' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'email' => 'Escribe un correo válido.',
            'correo_institucional.unique' => 'Ya existe una cuenta con este correo.',
            'id_facultad.exists' => 'Elige una facultad válida.',
            'password.min' => 'Usa al menos 8 caracteres.',
            'telefono.digits' => 'El teléfono debe tener 10 dígitos.',
            'acepto_terminos.accepted' => 'Debes aceptar el aviso de privacidad y las reglas.',
        ];
    }
}
