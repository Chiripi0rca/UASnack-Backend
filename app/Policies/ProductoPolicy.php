<?php

namespace App\Policies;

use App\Models\Producto;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Auth\Access\Response;

// Qué puede hacer un encargado con un producto (#163). Segunda capa detrás del global scope:
// si algún día alguien consulta sin el scope (withoutGlobalScopes), esto sigue protegiendo.
//
// Uso en el controlador del encargado (#99):
//   $this->authorize('update', $producto);
//
// Responde 404 y no 403 cuando el producto es de otra tienda: un 403 le confirmaría que existe.
class ProductoPolicy
{
    public function view(Usuario $usuario, Producto $producto): Response
    {
        return $this->esDeSuTienda($usuario, $producto);
    }

    public function update(Usuario $usuario, Producto $producto): Response
    {
        return $this->esDeSuTienda($usuario, $producto);
    }

    public function delete(Usuario $usuario, Producto $producto): Response
    {
        return $this->esDeSuTienda($usuario, $producto);
    }

    private function esDeSuTienda(Usuario $usuario, Producto $producto): Response
    {
        $esSuyo = $usuario->tieneRol(Rol::ENCARGADO_TIENDA)
            && Tienda::where('id', $producto->id_tienda)->where('id_dueno', $usuario->id)->exists();

        return $esSuyo ? Response::allow() : Response::denyAsNotFound();
    }
}
