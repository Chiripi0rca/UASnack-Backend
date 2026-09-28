<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FacultadController;
use Illuminate\Support\Facades\Route;

Route::get('/facultades', [FacultadController::class, 'obtenerFacultades']);
Route::post('/auth/registro', [AuthController::class, 'registro']);
