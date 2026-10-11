<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductoResource;
use App\Services\CatalogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class CatalogoController extends Controller
{
    public function __construct(private CatalogoService $catalogo)
    {
    }

    //GET /tiendas/{id}/productos?id_categoria=
    public function productos(Request $request, int $id): JsonResponse
    {

        //La categoria es opcional; si viene, debe ser entero (si no, 422)
        $request->validate(['id_categoria' => ['nullable', 'integer']]);

        $productos = $this->catalogo->productosDe( //aqui se manda el id de la tienda y de forma opcional categoria
            $this->catalogo->tiendaActiva($id), // 404 si no existe o está de baja
            $request->integer('id_categoria') ?: null // 0 (no vino) se convierte en null
        );

        return response()->json([
            'datos' => ProductoResource::collection($productos)->resolve($request),
            'meta' => ['total' => $productos->count()]
        ]);

    }

    //GET /productos/{id}
    public function detalleProducto(Request $request, int $id): JsonResponse
    {
        $detalle = $this->catalogo->producto($id); //aqui se manda el id para traer el detalle del producto 

        //se manda un solo producto
        return response()->json((new ProductoResource(($detalle))->resolve($request)));
    }
}
