<?php

namespace App\Enums;

enum EstadoProducto :string
{
    case Disponible = 'disponible';
    case Agotado = 'agotado';
}
