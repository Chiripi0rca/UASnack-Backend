<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacultadResource;
use App\Models\Facultades;
use Illuminate\Http\JsonResponse;

class FacultadController extends Controller
{
    public function obtenerFacultades(): JsonResponse
    {
        $facultades = Facultades::where('activa', true)->orderBy('nombre')->get();

        return response()->json([
            'datos' => FacultadResource::collection($facultades),
            'meta' => ['total' => $facultades->count()],
        ]);
    }
}
