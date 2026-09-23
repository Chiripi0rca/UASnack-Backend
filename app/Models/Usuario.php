<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    CONST CREATED_AT = 'creado_en';  //aqui le decimos a Eloquent que el campo creado_en es el que se va a usar para la fecha de creacion del registro
    CONST UPDATED_AT = null;

    protected $fillable = [ //aqui le decimos a Eloquent que campos se pueden llenar y cuales no, en este caso le decimos que los campos que se pueden llenar son los siguientes
        'nombre',
        'apellido',
        'correo_institucional',
        'matricula',
        'id_facultad',
        'hash_password',
        'telefono',
        'terminos_aceptados_en',
    ];

    protected $hidden = [ //aqui le decimos a Eloquent que el campo hash_password no se va a mostrar en la respuesta de la API es la contra parte de la propiedad $fillable, que es para decirle a Eloquent que campos se pueden llenar y cuales no, en este caso le decimos que el campo hash_password no se puede llenar desde la API
        'hash_password',
    ];
    protected $casts = [ // lo que hace casts es que por asi decir traduce el texto a plano a algo que sea mas facil leer, y con esa salida puedes manipular los datos de mejor forma
        'correo_verificado_en' => 'datetime',
        'terminos_aceptados_en' => 'datetime',
        'activo' => 'boolean',
        'saldo_cache' => 'decimal:2',
        'rol' => \App\Enums\RolUsuario::class,
    ];
}
