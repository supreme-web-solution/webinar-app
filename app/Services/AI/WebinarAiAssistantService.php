<?php

namespace App\Services\AI;

use App\Models\Webinar;
use App\Models\WebinarAiKnowledgeChunk;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebinarAiAssistantService
{
    private const DEFAULT_MIN_CONFIDENCE_SCORE = 0.18;
    private const NEEDS_HUMAN_ATTENTION_TOKEN = '__NEEDS_HUMAN_ATTENTION__';

    public function __construct(
        private readonly OpenAiEmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return array{answer: string, classification: string, sources: array<int, string>, needs_human_attention?: bool, attention_reason?: string}|null
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
        $sources = $this->extractSources($topChunks);

        if ($topChunks === []) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => [],
                'needs_human_attention' => true,
                'attention_reason' => 'no_knowledge_match',
            ];
        }

        $bestScore = (float) ($topChunks[0]['score'] ?? 0.0);
        $minimumConfidence = $this->resolveMinimumConfidenceScore($settings);
        if ($bestScore < $minimumConfidence) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => 'low_knowledge_confidence',
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

        if ($answer === self::NEEDS_HUMAN_ATTENTION_TOKEN) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => 'insufficient_context_for_confident_answer',
            ];
        }

        if ($this->looksUncertainOrUnavailable($answer)) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => 'uncertain_answer',
            ];
        }

        return [
            'answer' => $answer,
            'classification' => $classification,
            'sources' => $sources,
            'needs_human_attention' => false,
        ];
    }

    private function resolveMinimumConfidenceScore(array $settings): float
    {
        $value = (float) ($settings['minimum_confidence_score'] ?? self::DEFAULT_MIN_CONFIDENCE_SCORE);

        return max(0.0, min(1.0, $value));
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @return array<int, string>
     */
    private function extractSources(array $chunks): array
    {
        $sources = [];
        foreach ($chunks as $chunk) {
            $sourceLabel = (string) data_get($chunk, 'source_title', 'Knowledge Source');
            $sources[] = $sourceLabel;
        }

        return array_values(array_unique($sources));
    }

    private function buildNeedsHumanAttentionReply(): string
    {
        return 'I am an automated AI assistant, not a human host. I do not have enough verified information in this webinar knowledge base to answer that accurately. I have flagged your question so the webinar owner can review it and follow up with you.';
    }

    private function looksUncertainOrUnavailable(string $answer): bool
    {
        $text = mb_strtolower(trim($answer));
        if ($text === '') {
            return true;
        }

        return preg_match(
            '/\b(i\s*(am\s*)?(not\s*sure|unsure|don\'t\s*know|cannot\s*find|can\'t\s*find|could\s*not\s*find|no\s*(enough\s*)?information|not\s*provided|not\s*available|outside\s*(the\s*)?context|don\'t\s*have\s*that|not\s*configured)|appears\s*to\s*be|seems\s*to\s*be|looks\s*like|it\s*may\s*be|it\s*might\s*be)\b/i',
            $text,
        ) === 1;
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
            return '';
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
                            'content' => "You are {$assistantName}, representing the webinar host team.\n"
                                ."Rules:\n"
                                ."1) Answer using ONLY the provided context.\n"
                                ."2) Use confident, natural first-person host voice for attendees. Example tone: 'This webinar is about...'\n"
                                ."3) Do NOT use hedge or uncertainty phrases such as 'appears to be', 'seems', 'looks like', 'I think', 'I am unsure'.\n"
                                ."4) Do NOT mention internal context, knowledge base, documents, or retrieval.\n"
                                ."5) Adapt depth wisely:\n"
                                ."   - Greeting/simple question: 1 short sentence.\n"
                                ."   - Informational question: 2-4 clear sentences.\n"
                                ."   - Process/how-to question: short intro + numbered steps.\n"
                                ."   - Objection/concern question: acknowledge + clear next action.\n"
                                ."6) Keep answers practical and attendee-facing.\n"
                                ."7) If context is insufficient for a confident answer, output exactly: " . self::NEEDS_HUMAN_ATTENTION_TOKEN,
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
