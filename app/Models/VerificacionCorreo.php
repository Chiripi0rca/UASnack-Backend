<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionCorreo extends Model
{
    CONST CREATED_AT = 'creado_en';
    CONST UPDATED_AT = null;

    protected $table = 'verificaciones_correo'; //aqui le decimos a Eloquent que la tabla que vamos a usar es la de verificaciones_correo
    protected $fillable = ['id_usuario', 'tipo', 'codigo_hash', 'expira_en', 'intentos', 'consumido_en'];
    protected $casts = [
        'expira_en' => 'datetime',
        'consumido_en' => 'datetime',
    ];
}
