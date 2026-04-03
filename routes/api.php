<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\FreeAnalysisController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PriceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PortfolioImportController;

// Public routes
Route::post('/register',      [AuthController::class, 'register']);
Route::post('/login',         [AuthController::class, 'login']);
Route::post('/email/resend',       [AuthController::class, 'resendVerification']);
Route::post('/forgot-password',    [AuthController::class, 'forgotPassword']);
Route::post('/reset-password',     [AuthController::class, 'resetPassword']);
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');
Route::post('/analyze-free',  [FreeAnalysisController::class, 'analyze']);

// Protected routes
// DEMO MODE: swap 'demo.auth' back to 'auth:sanctum' to restore real authentication
Route::middleware('demo.auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // Prices
    Route::post('prices/refresh-crypto', [PriceController::class, 'refreshCrypto']);
    Route::post('prices/refresh-stocks', [PriceController::class, 'refreshStocks']);

    // Trades
    Route::apiResource('trades', TradeController::class);
    Route::patch('trades/{trade}/close', [TradeController::class, 'close']);

    // Positions
    Route::post('positions/analyze', [PositionController::class, 'analyze']);
    Route::post('positions/sell-recommendations', [PositionController::class, 'sellRecommendations']);
    Route::post('positions/{position}/analyze', [PositionController::class, 'analyzeOne']);
    Route::apiResource('positions', PositionController::class);

    // Portfolio screenshot import
    Route::post('portfolio/import-screenshot', [PortfolioImportController::class, 'importFromScreenshot']);

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('summary',       [AnalyticsController::class, 'summary']);
        Route::get('win-rate',      [AnalyticsController::class, 'winRate']);
        Route::get('pnl-by-symbol', [AnalyticsController::class, 'pnlBySymbol']);
        Route::get('ai-insights',   [AnalyticsController::class, 'aiInsights']);
    });
});