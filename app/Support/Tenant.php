<?php

namespace App\Support;

use App\Models\Rol;

// Quién hace la petición actual, ya resuelto por el middleware ResolverTenant (#162).
// Vive una sola petición: AppServiceProvider lo registra con scoped(), así que cada petición empieza vacía.
//
// Se pide por el constructor o con app(Tenant::class):
//   public function __construct(private Tenant $tenant) {}
//   $this->tenant->idTienda   // la tienda del encargado, sin volver a buscarla
//
// Para el alumno, idFacultad es solo una PREFERENCIA DE ORDEN (sus tiendas salen primero);
// nunca se usa para ocultarle tiendas de otras facultades.
class Tenant
{
    // Clave del rol (tabla roles): usuario, encargado_tienda o administrador
    public ?string $rol = null;

    public ?int $idFacultad = null;

    // Solo tiene valor si el rol es encargado_tienda
    public ?int $idTienda = null;

    public function esEncargado(): bool
    {
        return $this->rol === Rol::ENCARGADO_TIENDA;
    }
}
