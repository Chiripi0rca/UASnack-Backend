<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

// Hereda de Authenticatable (no de Model) para que Laravel lo reconozca como el usuario que inicia sesión:
// así $request->user() y auth()->user() devuelven un Usuario y el provider de config/auth.php puede validarlo.
class Usuario extends Authenticatable
{
    // Trait de Sanctum: le da al modelo el método createToken() (usado en AuthService::crearSesion)
    // y la relación con la tabla personal_access_tokens, donde se guardan los tokens de sesión.
    // Sin este trait, el login no puede generar el token.
    use HasApiTokens;
    CONST CREATED_AT = 'creado_en';  //aqui le decimos a Eloquent que el campo creado_en es el que se va a usar para la fecha de creacion del registro
    CONST UPDATED_AT = null;

    // Laravel busca la contraseña en la columna "password"; aquí se llama hash_password.
    protected $authPasswordName = 'hash_password';

    // La tabla usuarios no tiene columna remember_token ("recordarme" es de sesiones web, aquí se usan tokens de Sanctum).
    // Vacío = Laravel no intenta leerla ni guardarla.
    protected $rememberTokenName = '';

    protected $fillable = [ //aqui le decimos a Eloquent que campos se pueden llenar y cuales no, en este caso le decimos que los campos que se pueden llenar son los siguientes
        'nombre',
        'apellido',
        'correo_institucional',
        'matricula',
        'id_facultad',
        'hash_password',
        'telefono',
        'terminos_aceptados_en',
        'rol',
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
