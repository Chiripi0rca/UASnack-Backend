<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facultades extends Model
{
    protected $table = 'facultades';
    protected $fillable = ['id_campus', 'nombre', 'clave', 'activa'];
}
