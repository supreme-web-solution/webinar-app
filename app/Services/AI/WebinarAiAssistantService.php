<?php

namespace App\Services\AI;

use App\Models\ChatMessage;
use App\Models\Webinar;
use App\Models\WebinarAiKnowledgeChunk;
use App\Models\WebinarRegistrant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebinarAiAssistantService
{
    private const DEFAULT_MIN_CONFIDENCE_SCORE = 0.18;
    private const NEEDS_HUMAN_ATTENTION_TOKEN = '__NEEDS_HUMAN_ATTENTION__';
    private const RECENT_CONVERSATION_LIMIT = 10;
    private const RECENT_ASSISTANT_REPLY_LIMIT = 6;

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

        $intent = $this->detectIntentFlags($userMessage);
        $conversation = $this->buildConversationContext(
            (int) $webinar->id,
            (int) $registrant->id,
            self::RECENT_CONVERSATION_LIMIT,
        );

        $topChunks = $this->searchKnowledge($webinar->id, $userMessage, 5);
        $sources = $this->extractSources($topChunks);
        $contextHasActionableLink = $this->contextHasActionableLink($topChunks);

        if ($topChunks === []) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => [],
                'needs_human_attention' => true,
                'attention_reason' => ($intent['sales_or_link_request'] ?? false) ? 'sales_request_without_knowledge' : 'no_knowledge_match',
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
                'attention_reason' => ($intent['sales_or_link_request'] ?? false) ? 'sales_request_low_confidence' : 'low_knowledge_confidence',
            ];
        }

        if (($intent['needs_link'] ?? false) && ! $contextHasActionableLink) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => 'link_requested_but_not_in_knowledge',
            ];
        }

        $assistantName = trim((string) ($settings['assistant_name'] ?? 'Webinar AI Helper'));
        if ($assistantName === '') {
            $assistantName = 'Webinar AI Helper';
        }

        $answer = $this->generateAnswer(
            $assistantName,
            $userMessage,
            $topChunks,
            $conversation['conversation_text'],
            $intent,
            $contextHasActionableLink,
        );
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

        if (($intent['needs_link'] ?? false) && ! $this->containsUrl($answer)) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => 'link_requested_but_not_provided',
            ];
        }

        if ($this->isTooRepetitiveAnswer($answer, $conversation['recent_assistant_replies'])) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => 'repetitive_response_pattern',
            ];
        }

        $qaReview = $this->reviewGeneratedAnswer(
            $userMessage,
            $answer,
            $topChunks,
            $conversation['conversation_text'],
            $intent,
            $contextHasActionableLink,
        );

        if (($qaReview['needs_human_attention'] ?? false) === true) {
            return [
                'answer' => $this->buildNeedsHumanAttentionReply(),
                'classification' => $classification,
                'sources' => $sources,
                'needs_human_attention' => true,
                'attention_reason' => (string) ($qaReview['attention_reason'] ?? 'qa_review_escalation'),
            ];
        }

        $reviewedAnswer = trim((string) ($qaReview['answer'] ?? ''));
        if ($reviewedAnswer !== '') {
            $answer = $reviewedAnswer;
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

    /**
     * @return array{needs_link: bool, sales_or_link_request: bool}
     */
    private function detectIntentFlags(string $message): array
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return [
                'needs_link' => false,
                'sales_or_link_request' => false,
            ];
        }

        $needsLink = preg_match('/\b(link|url|buy\s*link|checkout|payment\s*link|direct\s*link|access\s*link)\b/u', $normalized) === 1;
        $salesRequest = preg_match('/\b(price|cost|how\s*much|purchase|buy|offer|discount|coupon|plan|upgrade|trial|get\s*started|start\s*today)\b/u', $normalized) === 1;

        return [
            'needs_link' => $needsLink,
            'sales_or_link_request' => $needsLink || $salesRequest,
        ];
    }

    /**
     * @return array{conversation_text: string, recent_assistant_replies: array<int, string>}
     */
    private function buildConversationContext(int $webinarId, int $registrantId, int $limit): array
    {
        $messages = ChatMessage::query()
            ->where('webinar_id', $webinarId)
            ->where('registrant_id', $registrantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $conversationLines = [];
        $recentAssistantReplies = [];

        foreach ($messages as $message) {
            $text = trim((string) $message->message);
            if ($text === '') {
                continue;
            }

            $text = $this->truncateText($text, 380);
            $speaker = $message->sender_type === 'attendee' ? 'Attendee' : 'Assistant';
            $conversationLines[] = $speaker.': '.$text;

            if ($speaker === 'Assistant') {
                $recentAssistantReplies[] = $text;
            }
        }

        if (count($recentAssistantReplies) > self::RECENT_ASSISTANT_REPLY_LIMIT) {
            $recentAssistantReplies = array_slice($recentAssistantReplies, -1 * self::RECENT_ASSISTANT_REPLY_LIMIT);
        }

        return [
            'conversation_text' => implode("\n", $conversationLines),
            'recent_assistant_replies' => $recentAssistantReplies,
        ];
    }

    private function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)).'...';
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    private function contextHasActionableLink(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if ($this->containsUrl((string) ($chunk['content'] ?? ''))) {
                return true;
            }

            $sourceUrl = trim((string) ($chunk['source_url'] ?? ''));
            if (filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
                return true;
            }
        }

        return false;
    }

    private function containsUrl(string $text): bool
    {
        return preg_match('/https?:\/\/[\S]+/i', $text) === 1;
    }

    /**
     * @param array<int, string> $recentAssistantReplies
     */
    private function isTooRepetitiveAnswer(string $answer, array $recentAssistantReplies): bool
    {
        $normalizedAnswer = mb_strtolower(trim($answer));
        if ($normalizedAnswer === '' || $recentAssistantReplies === []) {
            return false;
        }

        foreach ($recentAssistantReplies as $previousReply) {
            $normalizedPrevious = mb_strtolower(trim($previousReply));
            if ($normalizedPrevious === '') {
                continue;
            }

            similar_text($normalizedAnswer, $normalizedPrevious, $percent);
            if ($percent >= 82.0) {
                return true;
            }
        }

        return false;
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
            ->with(['source:id,title,source_url'])
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
                'source_url' => (string) optional($chunk->source)->source_url,
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
    private function generateAnswer(
        string $assistantName,
        string $question,
        array $chunks,
        string $conversationText,
        array $intent,
        bool $contextHasActionableLink,
    ): string
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
                    'temperature' => 0.25,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are {$assistantName}, representing the webinar host team.\n"
                                ."Rules:\n"
                                ."1) Answer using ONLY the provided context.\n"
                                ."2) Use confident, natural first-person host voice for attendees. Example tone: 'This webinar is about...'\n"
                                ."3) Do NOT use hedge or uncertainty phrases such as 'appears to be', 'seems', 'looks like', 'I think', 'I am unsure'.\n"
                                ."4) Do NOT mention internal context, knowledge base, documents, or retrieval.\n"
                                ."5) Use recent conversation to avoid repeating yourself and avoid empty promises.\n"
                                ."6) Never say you will send a link later. If user asks for a link and context has one, provide it directly.\n"
                                ."7) If user asks for sales/access/link and context does not contain the needed details, output exactly: " . self::NEEDS_HUMAN_ATTENTION_TOKEN ."\n"
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
                            'content' => "Recent conversation:\n".($conversationText !== '' ? $conversationText : '[No prior conversation]')
                                ."\n\nIntent flags:\n".json_encode($intent)
                                ."\nContext contains actionable link: ".($contextHasActionableLink ? 'yes' : 'no')
                                ."\n\nKnowledge context:\n{$contextText}\n\nAttendee question:\n{$question}",
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

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @param array<string, bool> $intent
     * @return array{needs_human_attention: bool, attention_reason?: string, answer?: string}
     */
    private function reviewGeneratedAnswer(
        string $question,
        string $draftAnswer,
        array $chunks,
        string $conversationText,
        array $intent,
        bool $contextHasActionableLink,
    ): array {
        if ($this->looksUncertainOrUnavailable($draftAnswer)) {
            return [
                'needs_human_attention' => true,
                'attention_reason' => 'qa_uncertain_answer',
            ];
        }

        if (($intent['needs_link'] ?? false) && ! $this->containsUrl($draftAnswer)) {
            return [
                'needs_human_attention' => true,
                'attention_reason' => 'qa_missing_link_in_answer',
            ];
        }

        $apiKey = (string) config('services.openai.api_key', '');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        if ($apiKey === '') {
            return [
                'needs_human_attention' => false,
                'answer' => $draftAnswer,
            ];
        }

        $contextText = collect($chunks)
            ->map(fn (array $item, int $index): string => '['.($index + 1).'] '.$item['content'])
            ->implode("\n\n");

        try {
            $response = Http::timeout(40)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an answer quality reviewer for webinar assistant responses. Return strict JSON only with keys: approve (boolean), needs_human_attention (boolean), reason (string), revised_answer (string). Approve only if answer is grounded in context, matches user intent, does not overpromise, and is not repetitive. If the user requested a link but none is present in answer and intent says needs_link=true, set needs_human_attention=true.',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Intent: '.json_encode($intent)
                                .'\nContext has actionable link: '.($contextHasActionableLink ? 'yes' : 'no')
                                .'\nRecent conversation:\n'.($conversationText !== '' ? $conversationText : '[No prior conversation]')
                                .'\n\nKnowledge context:\n'.$contextText
                                .'\n\nAttendee question:\n'.$question
                                .'\n\nDraft answer:\n'.$draftAnswer,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return [
                    'needs_human_attention' => false,
                    'answer' => $draftAnswer,
                ];
            }

            $raw = (string) data_get($response->json(), 'choices.0.message.content', '{}');
            $decoded = json_decode($raw, true);

            $needsHumanAttention = (bool) ($decoded['needs_human_attention'] ?? false);
            if ($needsHumanAttention) {
                return [
                    'needs_human_attention' => true,
                    'attention_reason' => trim((string) ($decoded['reason'] ?? 'qa_review_escalation')) ?: 'qa_review_escalation',
                ];
            }

            $revised = trim((string) ($decoded['revised_answer'] ?? ''));
            if ($revised !== '' && $revised !== self::NEEDS_HUMAN_ATTENTION_TOKEN) {
                return [
                    'needs_human_attention' => false,
                    'answer' => $revised,
                ];
            }

            return [
                'needs_human_attention' => false,
                'answer' => $draftAnswer,
            ];
        } catch (\Throwable $e) {
            Log::warning('WebinarAiAssistantService review exception', [
                'message' => $e->getMessage(),
            ]);

            return [
                'needs_human_attention' => false,
                'answer' => $draftAnswer,
            ];
        }
    }
}
