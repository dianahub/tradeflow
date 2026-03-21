<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\PositionController;     
use App\Http\Controllers\Api\PriceController;
use Illuminate\Support\Facades\Route;


// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('prices/refresh-crypto', [PriceController::class, 'refreshCrypto']);
    Route::post('prices/refresh-stocks', [PriceController::class, 'refreshStocks']);
    // Trades
    Route::apiResource('trades', TradeController::class);
    Route::patch('trades/{trade}/close', [TradeController::class, 'close']);

    // Positions (portfolio holdings)
    Route::apiResource('positions', PositionController::class);       
    Route::post('positions/analyze', [PositionController::class, 'analyze']);
    Route::post('positions/{position}/analyze', [PositionController::class, 'analyzeOne']);
    // Analytics
    Route::prefix('analytics')->group(function () {
    Route::get('summary',       [AnalyticsController::class, 'summary']);
    Route::get('win-rate',      [AnalyticsController::class, 'winRate']);
    Route::get('pnl-by-symbol', [AnalyticsController::class, 'pnlBySymbol']);
    Route::get('ai-insights',   [AnalyticsController::class, 'aiInsights']);
    });
});