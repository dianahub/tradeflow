<?php

use App\Http\Controllers\Api\PositionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// DEMO MODE: swap 'demo.auth' back to 'auth:sanctum' to restore real authentication
Route::middleware('demo.auth')->group(function () {
    Route::get('/positions', [PositionController::class, 'index']);
});
