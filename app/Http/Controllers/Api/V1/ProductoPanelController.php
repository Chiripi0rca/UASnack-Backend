<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarProductoRequest;
use App\Http\Requests\ActualizarStockRequest;
use App\Http\Requests\CrearProductoRequest;
use App\Http\Requests\SubirFotoProductoRequest;
use App\Http\Resources\ProductoPanelResource;
use App\Services\ProductoPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Endpoints del catálogo para el encargado de una tienda.
class ProductoPanelController extends Controller
{
    public function __construct(private ProductoPanelService $productoService)
    {
    }

    // GET /tienda/productos
    public function obtenerProductos(Request $request): JsonResponse
    {
        $productos = $this->productoService->productosDe($request->user());

        return response()->json([
            'datos' => ProductoPanelResource::collection($productos)->resolve($request),
            'meta'  => ['total' => $productos->count()],
        ]);
    }

    // POST /tienda/productos
    public function crearProducto(CrearProductoRequest $request): JsonResponse
    {
        $producto = $this->productoService->crearProducto($request->user(), $request->validated());

        return response()->json([
            'datos' => (new ProductoPanelResource($producto))->resolve($request),
        ], 201);
    }

    // PATCH /tienda/productos/{id}
    public function actualizarProducto(ActualizarProductoRequest $request, int $id): JsonResponse
    {
        $producto = $this->productoService->actualizarProducto($request->user(), $id, $request->validated());

        return response()->json([
            'datos' => (new ProductoPanelResource($producto))->resolve($request),
        ]);
    }

    // PATCH /tienda/productos/{id}/stock
    public function actualizarStock(ActualizarStockRequest $request, int $id): JsonResponse
    {
        $producto = $this->productoService->actualizarStock(
            $request->user(),
            $id,
            $request->validated()['stock_hoy']
        );

        return response()->json([
            'datos' => (new ProductoPanelResource($producto))->resolve($request),
        ]);
    }

    // POST /tienda/productos/{id}/foto (#238)
    // El Form Request ya revisó que "foto" sea una imagen válida; el servicio la guarda.
    // Responde el producto completo, con su foto_url nueva.
    public function subirFoto(SubirFotoProductoRequest $request, int $id): JsonResponse
    {
        $producto = $this->productoService->subirFoto($request->user(), $id, $request->file('foto'));

        return response()->json([
            'datos' => (new ProductoPanelResource($producto))->resolve($request),
        ]);
    }
}