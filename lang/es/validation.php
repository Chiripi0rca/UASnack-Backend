<?php

// Mensajes de validación en español. Laravel los usa cuando un Form Request no trae messages() propio.
// :attribute se reemplaza por el nombre del campo (ver 'attributes' abajo).
return [
    'required' => 'Este campo es obligatorio.',
    'email' => 'Escribe un correo válido.',
    'string' => 'Este campo debe ser texto.',
    'integer' => 'Este campo debe ser un número entero.',
    'digits' => 'Debe tener :digits dígitos.',
    'min' => [
        'string' => 'Usa al menos :min caracteres.',
    ],
    'max' => [
        'string' => 'Usa máximo :max caracteres.',
    ],
    'unique' => 'Este :attribute ya está registrado.',
    'exists' => 'Elige una opción válida.',
    'accepted' => 'Debes aceptar este campo.',

    // Nombre de cada campo tal como se lee en pantalla (en lugar de "correo_institucional").
    'attributes' => [
        'correo_institucional' => 'correo institucional',
        'password' => 'contraseña',
        'codigo' => 'código',
        'nombre' => 'nombre',
        'apellido' => 'apellido',
        'telefono' => 'teléfono',
        'id_facultad' => 'facultad',
    ],
];