<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DominiosInstitucional extends Model
{
    protected $table = 'dominios_institucionales';
    public $timestamps = false; // aqui le apagamos a Eloquent que no queremos que se creen los campos created_at y updated_at automaticamente
    protected $fillable = ['id_universidad', 'dominio', 'activo'];
    protected $casts = [
        'activo' => 'boolean',
    ];
}
