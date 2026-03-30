<?php

namespace App\Providers;

use App\Services\AI\AnalysisCacheService;
use App\Services\AI\AnthropicService;
use App\Services\AI\EmbeddingService;
use App\Services\AI\TradingAnalysisService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnthropicService::class);
        $this->app->singleton(EmbeddingService::class);
        $this->app->singleton(AnalysisCacheService::class);
        $this->app->singleton(TradingAnalysisService::class);
    }

    public function boot(): void
    {
        //
    }
}
