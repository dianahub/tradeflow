<?php

namespace App\Services\AI;

use App\Models\AiAnalysis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OpenAI\Client as OpenAIClient;

class EmbeddingService
{
    private ?OpenAIClient $client = null;
    private string $model = 'text-embedding-3-small';

    private function client(): OpenAIClient
    {
        if (!$this->client) {
            $apiKey = config('services.openai.key', '');
            $this->client = \OpenAI::client($apiKey);
        }
        return $this->client;
    }

    /**
     * Generate an embedding vector for the given text.
     * Returns a float[] of 1536 dimensions, or null on failure.
     */
    public function embed(string $text): ?array
    {
        $apiKey = config('services.openai.key', '');
        if (!$apiKey) {
            Log::warning('EmbeddingService: OPENAI_API_KEY not configured — skipping embedding');
            return null;
        }

        try {
            $response = $this->client()->embeddings()->create([
                'model' => $this->model,
                'input' => $text,
            ]);

            return $response->embeddings[0]->embedding;
        } catch (\Exception $e) {
            Log::error("EmbeddingService: failed to generate embedding: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Compute cosine similarity between two embedding vectors.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot  = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $val) {
            $dot   += $val * $b[$i];
            $normA += $val * $val;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Find the most similar past analyses for a given query text.
     *
     * Fetches up to 50 candidate records (most recent) that have embeddings,
     * computes cosine similarity in PHP, and returns the top-K results.
     *
     * @return Collection<AiAnalysis>
     */
    public function findSimilar(int $userId, string $queryText, string $analysisType, int $topK = 3): Collection
    {
        $apiKey = config('services.openai.key', '');
        if (!$apiKey) {
            return collect();
        }

        $queryEmbedding = $this->embed($queryText);
        if (!$queryEmbedding) {
            return collect();
        }

        // Fetch recent candidates that have been embedded
        $candidates = AiAnalysis::where('user_id', $userId)
            ->where('analysis_type', $analysisType)
            ->whereNotNull('embedding')
            ->latest()
            ->limit(50)
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // Score each candidate
        $scored = $candidates->map(function (AiAnalysis $analysis) use ($queryEmbedding) {
            $embedding = $analysis->getEmbeddingArray();
            if (!$embedding) {
                return null;
            }
            return [
                'analysis' => $analysis,
                'score'    => $this->cosineSimilarity($queryEmbedding, $embedding),
            ];
        })->filter()->sortByDesc('score')->take($topK);

        return $scored->pluck('analysis');
    }
}
