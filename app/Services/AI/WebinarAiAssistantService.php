<?php

namespace App\Services\AI;

use App\Models\Webinar;
use App\Models\WebinarAiKnowledgeChunk;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebinarAiAssistantService
{
    public function __construct(
        private readonly OpenAiEmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return array{answer: string, classification: string, sources: array<int, string>}|null
     */
    public function maybeGenerateReply(Webinar $webinar, WebinarRegistrant $registrant, string $userMessage): ?array
    {
        $settings = is_array($webinar->ai_settings) ? $webinar->ai_settings : [];
        $enabled = (bool) ($settings['enabled'] ?? false);
        $autoReplyEnabled = (bool) ($settings['auto_reply_enabled'] ?? false);

        if (! $enabled || ! $autoReplyEnabled) {
            return null;
        }

        $classification = $this->classifyMessage($userMessage, $settings);
        if ($classification !== 'question') {
            return null;
        }

        $topChunks = $this->searchKnowledge($webinar->id, $userMessage, 5);
        if ($topChunks === []) {
            return [
                'answer' => 'I could not find enough information in the webinar knowledge base yet. Please ask the host to add more sources.',
                'classification' => $classification,
                'sources' => [],
            ];
        }

        $assistantName = trim((string) ($settings['assistant_name'] ?? 'Webinar AI Helper'));
        if ($assistantName === '') {
            $assistantName = 'Webinar AI Helper';
        }

        $answer = $this->generateAnswer($assistantName, $userMessage, $topChunks);
        if ($answer === '') {
            return null;
        }

        $sources = [];
        foreach ($topChunks as $chunk) {
            $sourceLabel = (string) data_get($chunk, 'source_title', 'Knowledge Source');
            $sources[] = $sourceLabel;
        }

        return [
            'answer' => $answer,
            'classification' => $classification,
            'sources' => array_values(array_unique($sources)),
        ];
    }

    private function classifyMessage(string $message, array $settings): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return 'irrelevant';
        }

        if (preg_match('/\?|how|what|why|where|when|price|cost|bonus|offer|access|module|lesson/i', $trimmed)) {
            return 'question';
        }

        $apiKey = (string) config('services.openai.api_key', '');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        if ($apiKey === '') {
            return 'casual';
        }

        try {
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Classify webinar chat messages. Return JSON with key "type" only: question, casual, irrelevant.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $trimmed,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return 'casual';
            }

            $raw = (string) data_get($response->json(), 'choices.0.message.content', '{}');
            $decoded = json_decode($raw, true);
            $type = (string) ($decoded['type'] ?? 'casual');

            return in_array($type, ['question', 'casual', 'irrelevant'], true) ? $type : 'casual';
        } catch (\Throwable $e) {
            Log::warning('WebinarAiAssistantService classify failed', [
                'message' => $e->getMessage(),
            ]);

            return 'casual';
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchKnowledge(int $webinarId, string $query, int $limit = 5): array
    {
        $rows = WebinarAiKnowledgeChunk::query()
            ->select(['id', 'source_id', 'content', 'embedding'])
            ->where('webinar_id', $webinarId)
            ->latest('id')
            ->limit(400)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $queryEmbedding = $this->embeddingService->embedText($query);

        $scored = [];
        foreach ($rows as $chunk) {
            $score = 0.0;
            $embedding = is_array($chunk->embedding) ? $chunk->embedding : [];

            if ($queryEmbedding && $embedding) {
                $score = $this->cosineSimilarity($queryEmbedding, $embedding);
            } else {
                similar_text(mb_strtolower($query), mb_strtolower((string) $chunk->content), $pct);
                $score = $pct / 100;
            }

            $scored[] = [
                'content' => (string) $chunk->content,
                'score' => $score,
                'source_title' => (string) optional($chunk->source)->title ?: 'Knowledge Source',
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $size = min(count($a), count($b));
        if ($size === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $size; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    private function generateAnswer(string $assistantName, string $question, array $chunks): string
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');

        $contextText = collect($chunks)
            ->map(fn (array $item, int $index): string => '['.($index + 1).'] '.$item['content'])
            ->implode("\n\n");

        if ($apiKey === '') {
            return 'I found related webinar notes, but AI response is not configured yet. Please add OPENAI_API_KEY to enable full answers.';
        }

        try {
            $response = Http::timeout(45)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.3,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are {$assistantName}, a webinar support helper. Answer only from provided context. If context is weak, say you are unsure and ask for clarification.",
                        ],
                        [
                            'role' => 'user',
                            'content' => "Context:\n{$contextText}\n\nQuestion:\n{$question}",
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('WebinarAiAssistantService answer failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return '';
            }

            return trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        } catch (\Throwable $e) {
            Log::warning('WebinarAiAssistantService answer exception', [
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
