<?php

namespace App\Services\AI;

use App\Jobs\GenerateAnalysisEmbedding;
use App\Models\AiAnalysis;

class AnalysisCacheService
{
    // Cache TTLs per analysis type (minutes)
    private const TTL = [
        'portfolio'            => 60,
        'position'             => 60,
        'sell_recommendations' => 30,
        'journal_insights'     => 360,
    ];

    /**
     * Generate a SHA-256 hash of the input context data for cache keying.
     */
    public function hashContext(array $contextData): string
    {
        return hash('sha256', json_encode($contextData));
    }

    /**
     * Look up a cached analysis. Returns null if not found or expired.
     */
    public function find(int $userId, string $type, string $contextHash): ?AiAnalysis
    {
        $ttl = self::TTL[$type] ?? 60;

        return AiAnalysis::where('user_id', $userId)
            ->where('analysis_type', $type)
            ->where('context_hash', $contextHash)
            ->where('created_at', '>=', now()->subMinutes($ttl))
            ->latest()
            ->first();
    }

    /**
     * Persist a new analysis result and dispatch its embedding job.
     */
    public function store(
        int $userId,
        string $type,
        string $subjectKey,
        string $contextHash,
        string $promptSummary,
        string $analysisText,
        array $metadata = []
    ): AiAnalysis {
        $analysis = AiAnalysis::create([
            'user_id'       => $userId,
            'analysis_type' => $type,
            'subject_key'   => $subjectKey,
            'context_hash'  => $contextHash,
            'prompt_summary'=> $promptSummary,
            'analysis_text' => $analysisText,
            'metadata'      => $metadata,
        ]);

        $this->dispatchEmbedding($analysis);

        return $analysis;
    }

    /**
     * Dispatch the background job to generate and store the embedding.
     */
    public function dispatchEmbedding(AiAnalysis $analysis): void
    {
        GenerateAnalysisEmbedding::dispatch($analysis)->onQueue('default');
    }
}
