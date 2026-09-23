<?php

namespace App\Enums;

enum RolUsuario : string
{
    case Admin = 'administrador';
    case Usuario = 'usuario';
    case Encargado_Tienda = 'encargado_tienda';
}
