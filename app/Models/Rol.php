<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Catálogo de roles (tabla roles). Reemplaza al enum RolUsuario.
// Las constantes son las claves de la tabla: se usan en el código para no escribir el texto a mano,
// y son las mismas que van en las rutas (->middleware('rol:administrador')).
class Rol extends Model
{
    public const USUARIO = 'usuario';

    public const ENCARGADO_TIENDA = 'encargado_tienda';

    public const ADMINISTRADOR = 'administrador';

    protected $table = 'roles';

    public $timestamps = false;

    // Los roles los crea la migración; ningún endpoint los da de alta.
    protected $fillable = [];

    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'id_rol');
    }

    // id de un rol por su clave. Se pide pocas veces por petición, así que no hace falta caché.
    public static function idDe(string $clave): int
    {
        return static::where('clave', $clave)->valueOrFail('id');
    }
}
