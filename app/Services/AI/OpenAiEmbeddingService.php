<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiEmbeddingService
{
    /**
     * @return array<int, float>|null
     */
    public function embedText(string $text): ?array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');

        $clean = trim($text);
        if ($clean === '' || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => $model,
                    'input' => $clean,
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAiEmbeddingService request failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $vector = data_get($response->json(), 'data.0.embedding');
            if (! is_array($vector) || $vector === []) {
                return null;
            }

            return array_map(static fn ($v): float => (float) $v, $vector);
        } catch (\Throwable $e) {
            Log::warning('OpenAiEmbeddingService exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
