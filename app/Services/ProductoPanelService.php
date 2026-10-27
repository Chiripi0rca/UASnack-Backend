<?php

namespace App\Services;

use App\Enums\EstadoProducto;
use App\Exceptions\ErrorNegocio;
use App\Models\OpcionProducto;
use App\Models\Producto;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

// Lógica del catálogo que administra el encargado desde su panel.
class ProductoPanelService
{
    public function __construct(private CatalogoService $catalogo)
    {
    }

    // Lista todos los productos de la tienda del encargado, agotados incluidos:
    // el encargado necesita verlos para reponer stock.
    public function productosDe(Usuario $encargado): Collection
    {
        return Producto::where('id_tienda', $this->idTiendaDe($encargado))
            ->with('opciones')
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

        // Producto y opciones en la misma transacción: no queda un producto a medias.
        DB::transaction(function () use ($producto, $datos) {
            $producto->save();
            $this->guardarOpciones($producto, $datos);
        });

        // El alumno ve el cambio en su catálogo (#165).
        $this->catalogo->sincronizarMongo($producto);

        return $producto->fresh('opciones');
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

        DB::transaction(function () use ($producto, $datos) {
            $producto->save();
            $this->guardarOpciones($producto, $datos);
        });

        // El alumno ve el cambio en su catálogo (#165).
        $this->catalogo->sincronizarMongo($producto);

        return $producto->fresh('opciones');
    }

    // Cambia el stock del día. 0 = agotado; más de 0 = disponible otra vez.
    // El producto agotado NO se oculta
    public function actualizarStock(Usuario $encargado, int $id, int $stock): Producto
    {
        $producto = $this->buscarProducto($encargado, $id);

        $producto->stock_hoy = $stock;
        $producto->estado_producto = $this->estadoSegunStock($stock);
        $producto->save();

        $this->catalogo->sincronizarMongo($producto);

        return $producto->fresh('opciones');
    }

    // #238: guarda la foto del producto en el disco "public" y deja su URL en foto_url.
    // Si el producto ya tenía una foto subida desde el panel, esa se borra al final.
    // Si su foto era una URL de otro sitio, no se toca: no es un archivo nuestro.
    public function subirFoto(Usuario $encargado, int $id, UploadedFile $foto): Producto
    {
        // Solo un producto de su tienda; uno ajeno responde 404.
        $producto = $this->buscarProducto($encargado, $id);

        // Se apunta dónde está la foto vieja, para borrarla cuando la nueva ya esté guardada.
        $rutaAnterior = $this->rutaEnDisco($producto->foto_url);

        // Guarda el archivo en storage/app/public/productos/{id_tienda}/ con un nombre al azar:
        // así dos fotos nunca se pisan y el navegador no se queda con una vieja en caché.
        $ruta = $foto->store("productos/{$producto->id_tienda}", 'public');

        try {
            // URL pública de la foto, por ejemplo http://localhost:8000/storage/productos/1/abc.jpg
            $producto->foto_url = Storage::disk('public')->url($ruta);
            $producto->save();
        } catch (Throwable $e) {
            // Si el producto no se pudo guardar, se borra el archivo para no dejar basura.
            Storage::disk('public')->delete($ruta);

            throw $e;
        }

        // La nueva ya quedó guardada: ahora sí se borra la anterior.
        if ($rutaAnterior) {
            Storage::disk('public')->delete($rutaAnterior);
        }

        // Limpia la caché del catálogo para que el alumno vea la foto nueva al momento (#165).
        $this->catalogo->sincronizarMongo($producto);

        return $producto->fresh('opciones');
    }

    // Convierte la URL de una foto en su ruta dentro del disco "public":
    //   "http://localhost:8000/storage/productos/1/abc.jpg" → "productos/1/abc.jpg"
    // Regresa null si no hay foto o si la URL no es de nuestro disco (una foto de otro sitio).
    private function rutaEnDisco(?string $url): ?string
    {
        // El principio que tienen todas las URLs del disco, por ejemplo "http://localhost:8000/storage/"
        $base = Storage::disk('public')->url('');

        if (! $url || ! str_starts_with($url, $base)) {
            return null;
        }

        return substr($url, strlen($base));
    }

    // Extras y quitables del producto (HU-04). Cada lista que llega reemplaza a la anterior
    // de ese tipo; la que no llega se queda como estaba.
    private function guardarOpciones(Producto $producto, array $datos): void
    {
        $listas = [
            OpcionProducto::EXTRA => $datos['extras'] ?? null,
            OpcionProducto::QUITABLE => $datos['quitables'] ?? null,
        ];

        foreach ($listas as $tipo => $opciones) {
            if ($opciones === null) {
                continue;
            }

            $producto->opciones()->where('tipo', $tipo)->delete();

            foreach ($opciones as $opcion) {
                $producto->opciones()->create([
                    'tipo' => $tipo,
                    'nombre' => $opcion['nombre'],
                    // Un quitable nunca cuesta, aunque el cliente mande precio.
                    'precio' => $tipo === OpcionProducto::EXTRA ? $opcion['precio'] : 0,
                ]);
            }
        }
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
