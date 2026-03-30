<?php

namespace App\Jobs;

use App\Models\AiAnalysis;
use App\Services\AI\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateAnalysisEmbedding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // seconds between retries

    public function __construct(private readonly AiAnalysis $analysis) {}

    public function handle(EmbeddingService $embeddings): void
    {
        // Track attempt in the jobs table
        DB::table('ai_embedding_jobs')->updateOrInsert(
            ['ai_analysis_id' => $this->analysis->id],
            ['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]
        );

        $embedding = $embeddings->embed($this->analysis->prompt_summary);

        if (!$embedding) {
            DB::table('ai_embedding_jobs')->where('ai_analysis_id', $this->analysis->id)->update([
                'status'     => 'failed',
                'last_error' => 'EmbeddingService returned null',
                'updated_at' => now(),
            ]);
            Log::warning("GenerateAnalysisEmbedding: failed for analysis #{$this->analysis->id}");
            return;
        }

        $this->analysis->update(['embedding' => json_encode($embedding)]);

        DB::table('ai_embedding_jobs')->where('ai_analysis_id', $this->analysis->id)->update([
            'status'     => 'done',
            'last_error' => null,
            'updated_at' => now(),
        ]);

        Log::info("GenerateAnalysisEmbedding: embedded analysis #{$this->analysis->id}");
    }

    public function failed(\Throwable $e): void
    {
        DB::table('ai_embedding_jobs')->where('ai_analysis_id', $this->analysis->id)->update([
            'status'     => 'failed',
            'last_error' => $e->getMessage(),
            'updated_at' => now(),
        ]);
        Log::error("GenerateAnalysisEmbedding: job failed for analysis #{$this->analysis->id}: {$e->getMessage()}");
    }
}
