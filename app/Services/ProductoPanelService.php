<?php

namespace App\Services;

use App\Enums\EstadoProducto;
use App\Exceptions\ErrorNegocio;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

// Lógica del catálogo que administra el encargado desde su panel.
class ProductoPanelService
{
    // Lista todos los productos de la tienda del encargado, agotados incluidos:
    // el encargado necesita verlos para reponer stock.
    public function productosDe(Usuario $encargado): Collection
    {
        return Producto::where('id_tienda', $this->idTiendaDe($encargado))
            ->orderBy('nombre')
            ->get();
    }

    // Crea un producto en la tienda del encargado.
    public function crearProducto(Usuario $encargado, array $datos): Producto
    {
        // new + fill en lugar de create(): así podemos poner a mano los campos
        // que NO vienen del cliente antes de guardar.
        $producto = new Producto();
        $producto->fill($datos);

        // La tienda sale del encargado, nunca del body.
        $producto->id_tienda = $this->idTiendaDe($encargado);

        // El estado lo decide el servidor según el stock inicial.
        $producto->estado_producto = $this->estadoSegunStock($datos['stock_hoy']);

        $producto->save();

        return $producto->fresh();
    }

    // Edita datos del producto.
    public function actualizarProducto(Usuario $encargado, int $id, array $datos): Producto
    {
        $producto = $this->buscarProducto($encargado, $id);

        // Igual que en tiendas: primero se mezclan los cambios en memoria
        $producto->fill($datos);

        //se valida con la mezcla, por si solo mandaron uno de los dos tiempos.
        if ($producto->tiempo_prep_max < $producto->tiempo_prep_min) {
            throw ValidationException::withMessages([
                'tiempo_prep_max' => 'El tiempo máximo no puede ser menor que el mínimo.',
            ]);
        }

        $producto->save();

        return $producto->fresh();
    }

    // Cambia el stock del día. 0 = agotado; más de 0 = disponible otra vez.
    // El producto agotado NO se oculta
    public function actualizarStock(Usuario $encargado, int $id, int $stock): Producto
    {
        $producto = $this->buscarProducto($encargado, $id);

        $producto->stock_hoy = $stock;
        $producto->estado_producto = $this->estadoSegunStock($stock);
        $producto->save();

        return $producto->fresh();
    }

    // Busca un producto solo dentro de la tienda del encargado.
    // Si es de otra tienda, responde 404 (no 403) para no confirmar que existe.
    private function buscarProducto(Usuario $encargado, int $id): Producto
    {
        $producto = Producto::where('id_tienda', $this->idTiendaDe($encargado))->find($id);

        if (! $producto) {
            throw new ErrorNegocio('NO_ENCONTRADO', 'El producto no existe.', 404);
        }

        return $producto;
    }

    // Regresa el id de la tienda del encargado.
    private function idTiendaDe(Usuario $encargado): int
    {
        $idTienda = Tienda::where('id_dueno', $encargado->id)->value('id');

        if (! $idTienda) {
            throw new ErrorNegocio('SIN_TIENDA_ASIGNADA', 'No tienes una tienda asignada.', 403);
        }

        return $idTienda;
    }

    // Decide el estado según el stock. ->value saca el texto del enum ('agotado'),
    // que es lo que se guarda en la columna.
    private function estadoSegunStock(int $stock): string
    {
        return $stock > 0
            ? EstadoProducto::Disponible->value
            : EstadoProducto::Agotado->value;
    }
}