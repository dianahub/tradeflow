<?php

namespace App\Services\AI;

use App\Exceptions\AnthropicApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicService
{
    private string $apiKey;
    private string $model;
    private string $apiVersion = '2023-06-01';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key', '');
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * Send a text prompt to Claude and return the response text.
     *
     * @throws AnthropicApiException
     */
    public function complete(string $prompt, int $maxTokens = 800, ?string $model = null): string
    {
        $payload = [
            'model'      => $model ?? $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        Log::info('Anthropic complete()', ['model' => $payload['model'], 'key_prefix' => substr($this->apiKey, 0, 16)]);
        $response = $this->callWithRetry($payload);

        return $response['content'][0]['text'] ?? '';
    }

    /**
     * Send a prompt with an image (vision) to Claude and return the response text.
     *
     * @throws AnthropicApiException
     */
    public function completeWithVision(string $prompt, string $base64Image, string $mimeType, int $maxTokens = 4096): string
    {
        $payload = [
            'model'      => 'claude-opus-4-6',
            'max_tokens' => $maxTokens,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mimeType,
                                'data'       => $base64Image,
                            ],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ],
            ],
        ];

        $response = $this->callWithRetry($payload, maxAttempts: 2); // fewer retries for expensive vision calls

        return $response['content'][0]['text'] ?? '';
    }

    /**
     * Call the Anthropic API with exponential backoff retry on rate limits and server errors.
     *
     * @throws AnthropicApiException
     */
    private function callWithRetry(array $payload, int $maxAttempts = 3): array
    {
        $attempt  = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            try {
                $response = Http::withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type'      => 'application/json',
                ])->timeout(60)->post('https://api.anthropic.com/v1/messages', $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                $statusCode = $response->status();
                $lastError  = "HTTP {$statusCode}: " . $response->body();

                // Retry on rate limits (429) and server errors (5xx)
                if ($statusCode === 429 || $statusCode >= 500) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        $delay = (2 ** ($attempt - 1)); // 1s, 2s, 4s
                        Log::warning("Anthropic API error ({$statusCode}), retrying in {$delay}s (attempt {$attempt}/{$maxAttempts})");
                        sleep($delay);
                        continue;
                    }
                }

                // Non-retryable error (4xx except 429)
                Log::error('Anthropic non-retryable error', ['status' => $statusCode, 'body' => $response->body(), 'key_prefix' => substr($this->apiKey, 0, 16)]);
                throw new AnthropicApiException("Anthropic API error: {$lastError}", $statusCode);

            } catch (AnthropicApiException $e) {
                throw $e;
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $attempt++;
                if ($attempt < $maxAttempts) {
                    sleep(2 ** ($attempt - 1));
                    continue;
                }
            }
        }

        Log::error("Anthropic API failed after {$maxAttempts} attempts: {$lastError}");
        throw new AnthropicApiException("AI unavailable. Last error: {$lastError}");
    }
}
