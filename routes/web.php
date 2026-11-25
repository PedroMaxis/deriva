<?php

use App\Http\Controllers\BottleController;
use App\Http\Controllers\SeaController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'deriva');

Route::get('/mar', [SeaController::class, 'index']);
Route::get('/mar/aqui', [SeaController::class, 'here']);
Route::get('/mar/diario', [SeaController::class, 'logbook']);

Route::get('/garrafas/{id}', [BottleController::class, 'show']);
Route::post('/garrafas', [BottleController::class, 'store'])->middleware('throttle:lancamentos');
Route::post('/garrafas/{id}/abrir', [BottleController::class, 'open']);
Route::post('/garrafas/{id}/resposta', [BottleController::class, 'reply'])->middleware('throttle:lancamentos');
