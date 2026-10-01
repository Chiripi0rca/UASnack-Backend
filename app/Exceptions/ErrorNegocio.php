<?php

namespace App\Exceptions;

use Exception;

// Error de una regla de negocio (código incorrecto, credenciales inválidas, etc.).
// El servicio la lanza y bootstrap/app.php la convierte en { error: { codigo, mensaje } }.
class ErrorNegocio extends Exception
{
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $status = 422,
        public readonly array $headers = [],
    ) {
        parent::__construct($mensaje);
    }
}