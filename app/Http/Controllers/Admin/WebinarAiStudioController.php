<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebinarAiStudioController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $apiKey = $this->resolveHeygenApiKey();
        if ($apiKey === '') {
            return response()->json([
                'message' => 'HEYGEN_API_KEY is not configured.',
            ], 422);
        }

        try {
            $payload = $this->getHeygenAvatarOptionsPayload($apiKey);
            $payload['openai_voices'] = $this->openAiVoiceOptions();

            Log::info('webinar.ai.options.loaded', [
                'user_id' => $request->user()?->id,
                'avatars_count' => count($payload['avatars'] ?? []),
                'openai_voices_count' => count($payload['openai_voices'] ?? []),
                'stale' => (bool) ($payload['stale'] ?? false),
            ]);

            return response()->json($payload);
        } catch (\Throwable $e) {
            $stalePayload = Cache::get('webinar:ai:heygen:avatars:stale:v1');
            if (is_array($stalePayload)) {
                $stalePayload['openai_voices'] = $this->openAiVoiceOptions();
                Log::warning('webinar.ai.options.failed_using_stale', [
                    'user_id' => $request->user()?->id,
                    'message' => $e->getMessage(),
                    'avatars_count' => count($stalePayload['avatars'] ?? []),
                ]);

                $stalePayload['stale'] = true;
                $stalePayload['message'] = 'Using cached HeyGen options due to provider timeout.';

                return response()->json($stalePayload);
            }

            Log::warning('webinar.ai.options.failed', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'key_fingerprint' => $this->fingerprintKey($apiKey),
            ]);

            return response()->json([
                'avatars' => [],
                'openai_voices' => $this->openAiVoiceOptions(),
                'stale' => true,
                'message' => 'HeyGen avatars are temporarily unavailable. OpenAI voices are still available.',
            ]);
        }
    }

    public function generateScript(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'webinar_type' => ['required', 'string', 'max:120'],
            'audience' => ['required', 'string', 'max:300'],
            'goal' => ['required', 'string', 'max:300'],
            'tone' => ['nullable', 'string', 'max:120'],
            'duration_minutes' => ['required', 'integer', 'min:20', 'max:120'],
            'language' => ['nullable', 'string', 'max:40'],
            'host_name' => ['nullable', 'string', 'max:255'],
        ]);

        $apiKey = (string) config('services.openai.api_key', '');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');

        if ($apiKey === '') {
            return response()->json([
                'message' => 'OPENAI_API_KEY is not configured.',
            ], 422);
        }

        $tone = trim((string) ($payload['tone'] ?? 'expert, engaging, and practical'));
        $language = trim((string) ($payload['language'] ?? 'English'));
        $hostName = trim((string) ($payload['host_name'] ?? ''));

        $systemPrompt = 'You are a senior webinar scriptwriter. Write complete, long-form webinar narration for spoken delivery.';

        $userPrompt = implode("\n", [
            'Create a long webinar script using the following settings:',
            'Topic: '.$payload['topic'],
            'Webinar type: '.$payload['webinar_type'],
            'Audience: '.$payload['audience'],
            'Primary goal: '.$payload['goal'],
            'Tone: '.$tone,
            'Language: '.$language,
            'Target duration in minutes: '.(int) $payload['duration_minutes'],
            'Host name: '.($hostName !== '' ? $hostName : 'not provided'),
            '',
            'Output requirements:',
            '1) Return plain text only (no markdown).',
            '2) Return only the spoken narration content as flowing paragraphs.',
            '3) Do not include timestamps, time ranges, scene markers, slide markers, labels, headings, bullets, or bracketed cues.',
            '4) Do not include production notes or camera/stage directions of any kind.',
            '5) Keep transitions natural for spoken delivery by a single presenter avatar.',
            '6) Include opening hook, credibility section, core teaching blocks, objection handling, and clear CTA moments in the narration itself.',
            '7) Target approximately 130 spoken words per minute for pacing.',
            '8) If host name is provided, introduce the speaker naturally using that exact name. Do not use placeholders such as [your name].',
            '9) If host name is not provided, do not mention any name and do not invent one.',
        ]);

        try {
            $response = Http::timeout(120)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.7,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Failed to generate script from AI provider.',
                    'provider_status' => $response->status(),
                ], 502);
            }

            $script = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            $script = $this->sanitizeGeneratedNarration($script);
            if ($script === '') {
                return response()->json([
                    'message' => 'AI provider returned an empty script.',
                ], 502);
            }

            return response()->json([
                'script' => $script,
                'model' => $model,
                'estimated_words' => str_word_count($script),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webinar AI script generation failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate script due to a server error.',
            ], 500);
        }
    }

    public function generateVideo(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'script' => ['required', 'string', 'min:300'],
            'avatar_id' => ['required', 'string', 'max:255'],
            'openai_voice' => ['required', 'string', 'max:60'],
            'intro_duration_seconds' => ['nullable', 'integer', 'min:20', 'max:60'],
            'aspect_ratio' => ['nullable', 'in:16:9,9:16,1:1'],
            'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $apiKey = $this->resolveHeygenApiKey();
        if ($apiKey === '') {
            return response()->json([
                'message' => 'HEYGEN_API_KEY is not configured.',
            ], 422);
        }

        [$width, $height] = $this->resolveVideoDimensions((string) ($payload['aspect_ratio'] ?? '16:9'));
        $background = (string) ($payload['background_color'] ?? '#F8FAFC');
        $scriptLength = mb_strlen((string) $payload['script']);
        $introDurationSeconds = (int) ($payload['intro_duration_seconds'] ?? 45);
        [$introScript, $remainingScript] = $this->splitScriptForAvatarIntro((string) $payload['script'], $introDurationSeconds);
        $openAiVoices = collect($this->openAiVoiceOptions())->pluck('id')->filter()->all();

        if ($openAiVoices !== [] && ! in_array((string) $payload['openai_voice'], $openAiVoices, true)) {
            return response()->json([
                'message' => 'Selected OpenAI voice is invalid.',
            ], 422);
        }

        $optionsPayload = $this->getHeygenAvatarOptionsPayload($apiKey);
        $validAvatarIds = collect($optionsPayload['avatars'] ?? [])->pluck('id')->filter()->all();

        if ($validAvatarIds !== [] && ! in_array($payload['avatar_id'], $validAvatarIds, true)) {
            return response()->json([
                'message' => 'Selected avatar is invalid for your HeyGen account.',
            ], 422);
        }

        if ($validAvatarIds === []) {
            Log::warning('webinar.ai.video.generate.validation_relaxed', [
                'user_id' => $request->user()?->id,
                'reason' => 'heygen_avatars_unavailable_or_empty',
                'avatar_id' => $payload['avatar_id'],
                'openai_voice' => $payload['openai_voice'],
            ]);
        }

        Log::info('webinar.ai.video.generate.requested', [
            'user_id' => $request->user()?->id,
            'title' => (string) $payload['title'],
            'avatar_id' => (string) $payload['avatar_id'],
            'openai_voice' => (string) ($payload['openai_voice'] ?? ''),
            'aspect_ratio' => (string) ($payload['aspect_ratio'] ?? '16:9'),
            'width' => $width,
            'height' => $height,
            'background_color' => $background,
            'script_length' => $scriptLength,
            'intro_duration_seconds' => $introDurationSeconds,
            'intro_words' => str_word_count($introScript),
            'remaining_words' => str_word_count($remainingScript),
        ]);

        $slidePlan = $this->generateSlidePlan($remainingScript, (string) $payload['title']);

        try {
            $audioUrl = $this->generateOpenAiNarrationAudioUrl($introScript, (string) $payload['openai_voice']);
        } catch (\Throwable $e) {
            Log::warning('webinar.ai.video.generate.tts_failed', [
                'user_id' => $request->user()?->id,
                'voice' => (string) ($payload['openai_voice'] ?? ''),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate narration audio from OpenAI voice.',
            ], 502);
        }

        $videoInput = [
            'character' => [
                'type' => 'avatar',
                'avatar_id' => $payload['avatar_id'],
            ],
            'voice' => [
                'type' => 'audio',
                'audio_url' => $audioUrl,
            ],
            'background' => [
                'type' => 'color',
                'value' => $background,
            ],
        ];

        $generateTimeoutSeconds = max(120, (int) env('HEYGEN_GENERATE_TIMEOUT_SECONDS', 240));
        $generateConnectTimeoutSeconds = max(10, (int) env('HEYGEN_GENERATE_CONNECT_TIMEOUT_SECONDS', 30));
        $generateRetryCount = max(0, (int) env('HEYGEN_GENERATE_RETRY_COUNT', 1));

        try {
            $response = Http::timeout($generateTimeoutSeconds)
                ->connectTimeout($generateConnectTimeoutSeconds)
                ->retry($generateRetryCount, 2000, function (\Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, false)
                ->withHeaders($this->heygenHeaders($apiKey))
                ->post('https://api.heygen.com/v2/video/generate', [
                    'caption' => false,
                    'title' => $payload['title'],
                    'dimension' => [
                        'width' => $width,
                        'height' => $height,
                    ],
                    'video_inputs' => [$videoInput],
                ]);

            if (! $response->successful()) {
                Log::warning('webinar.ai.video.generate.rejected', [
                    'user_id' => $request->user()?->id,
                    'provider_status' => $response->status(),
                    'provider_body' => substr($response->body(), 0, 2000),
                ]);

                return response()->json([
                    'message' => 'HeyGen rejected the generation request.',
                    'provider_status' => $response->status(),
                    'provider_response' => $response->json(),
                ], 502);
            }

            $responseJson = $response->json();
            $videoId = $this->extractVideoId($responseJson);

            if ($videoId === null) {
                Log::warning('webinar.ai.video.generate.missing_video_id', [
                    'user_id' => $request->user()?->id,
                    'provider_status' => $response->status(),
                    'provider_body' => substr($response->body(), 0, 2000),
                ]);

                return response()->json([
                    'message' => 'HeyGen did not return a video id.',
                    'provider_response' => $responseJson,
                ], 502);
            }

            Log::info('webinar.ai.video.generate.accepted', [
                'user_id' => $request->user()?->id,
                'video_id' => $videoId,
            ]);

            Cache::put($this->composeMetaCacheKey($videoId), [
                'title' => (string) $payload['title'],
                'remaining_script' => $remainingScript,
                'slide_plan' => $slidePlan,
                'openai_voice' => (string) ($payload['openai_voice'] ?? ''),
                'aspect_ratio' => (string) ($payload['aspect_ratio'] ?? '16:9'),
            ], now()->addHours(6));

            return response()->json([
                'video_id' => $videoId,
                'status' => 'pending',
                'provider' => 'heygen',
                'intro_script' => $introScript,
                'remaining_script' => $remainingScript,
                'slide_plan' => $slidePlan,
                'intro_duration_seconds' => $introDurationSeconds,
                'openai_voice' => (string) ($payload['openai_voice'] ?? ''),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('webinar.ai.video.generate.timeout', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'timeout_seconds' => $generateTimeoutSeconds,
                'connect_timeout_seconds' => $generateConnectTimeoutSeconds,
            ]);

            return response()->json([
                'message' => 'HeyGen is taking too long to respond. Please try again in a moment.',
            ], 504);
        } catch (\Throwable $e) {
            Log::warning('Webinar AI HeyGen generate failed', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'message' => 'Failed to start video generation due to a server error.',
            ], 500);
        }
    }

    public function videoStatus(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'video_id' => ['required', 'string', 'max:255'],
        ]);

        $apiKey = $this->resolveHeygenApiKey();
        if ($apiKey === '') {
            return response()->json([
                'message' => 'HEYGEN_API_KEY is not configured.',
            ], 422);
        }

        try {
            Log::info('webinar.ai.video.status.requested', [
                'user_id' => $request->user()?->id,
                'video_id' => $payload['video_id'],
            ]);

            $statusResponse = Http::timeout(45)
                ->withHeaders($this->heygenHeaders($apiKey))
                ->get('https://api.heygen.com/v1/video_status.get', [
                    'video_id' => $payload['video_id'],
                ]);

            if (! $statusResponse->successful()) {
                Log::warning('webinar.ai.video.status.rejected', [
                    'user_id' => $request->user()?->id,
                    'video_id' => $payload['video_id'],
                    'provider_status' => $statusResponse->status(),
                    'provider_body' => substr($statusResponse->body(), 0, 2000),
                ]);

                return response()->json([
                    'message' => 'Failed to retrieve HeyGen status.',
                    'provider_status' => $statusResponse->status(),
                ], 502);
            }

            $statusJson = $statusResponse->json();
            $status = strtolower((string) ($this->extractValue($statusJson, [
                'data.status',
                'status',
            ]) ?? 'unknown'));

            $videoUrl = $this->extractValue($statusJson, [
                'data.video_url',
                'video_url',
                'data.url',
                'url',
                'data.video_share_url',
                'video_share_url',
            ]);

            if (($status === 'completed' || $status === 'success') && $videoUrl === null) {
                $shareResponse = Http::timeout(45)
                    ->withHeaders($this->heygenHeaders($apiKey))
                    ->post('https://api.heygen.com/v1/video/share', [
                        'video_id' => $payload['video_id'],
                    ]);

                if ($shareResponse->successful()) {
                    $shareJson = $shareResponse->json();
                    $videoUrl = $this->extractValue($shareJson, [
                        'data.video_url',
                        'video_url',
                        'data.url',
                        'url',
                    ]);
                } else {
                    Log::warning('webinar.ai.video.status.share_failed', [
                        'user_id' => $request->user()?->id,
                        'video_id' => $payload['video_id'],
                        'provider_status' => $shareResponse->status(),
                        'provider_body' => substr($shareResponse->body(), 0, 2000),
                    ]);
                }
            }

            if (($status === 'completed' || $status === 'success') && is_string($videoUrl) && trim($videoUrl) !== '') {
                $composed = $this->resolveOrComposeLongFormVideo($payload['video_id'], $videoUrl);
                if (is_string($composed) && trim($composed) !== '') {
                    $videoUrl = $composed;
                }
            }

            $composeState = Cache::get($this->composeStateCacheKey($payload['video_id']));
            $composeStatus = is_array($composeState) ? (string) ($composeState['status'] ?? '') : '';
            $composingLongForm = $composeStatus === 'processing';

            $cloudinaryUploaded = false;

            if (($status === 'completed' || $status === 'success') && $videoUrl === null) {
                $candidateUrl = $this->extractValue($statusJson, [
                    'data.video_download_url',
                    'video_download_url',
                    'data.download_url',
                    'download_url',
                ]);

                if ($candidateUrl !== null) {
                    $uploadedUrl = $this->uploadToCloudinary((string) $candidateUrl, $payload['video_id']);
                    if ($uploadedUrl !== null) {
                        $videoUrl = $uploadedUrl;
                        $cloudinaryUploaded = true;
                    }
                }
            }

            Log::info('webinar.ai.video.status.resolved', [
                'user_id' => $request->user()?->id,
                'video_id' => $payload['video_id'],
                'status' => $status,
                'has_video_url' => $videoUrl !== null,
                'cloudinary_uploaded' => $cloudinaryUploaded,
                'compose_status' => $composeStatus,
            ]);

            return response()->json([
                'video_id' => $payload['video_id'],
                'status' => $status,
                'video_url' => $videoUrl,
                'cloudinary_uploaded' => $cloudinaryUploaded,
                'composing_long_form' => $composingLongForm,
                'compose_status' => $composeStatus,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webinar AI HeyGen status failed', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'message' => 'Failed to check video status due to a server error.',
            ], 500);
        }
    }

    public function createWebinar(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'webinar_id' => ['nullable', 'integer', 'exists:webinars,id'],
            'title' => ['required', 'string', 'max:255'],
            'host_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'script' => ['required', 'string', 'min:300'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'video_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:80'],
            'avatar_id' => ['nullable', 'string', 'max:255'],
            'voice_id' => ['nullable', 'string', 'max:255'],
            'webinar_type' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'max:300'],
            'goal' => ['nullable', 'string', 'max:300'],
            'heygen_video_id' => ['nullable', 'string', 'max:255'],
            'video_generation_status' => ['nullable', 'string', 'max:40'],
            'intro_script' => ['nullable', 'string'],
            'remaining_script' => ['nullable', 'string'],
            'slide_plan' => ['nullable', 'array'],
            'intro_duration_seconds' => ['nullable', 'integer', 'min:20', 'max:60'],
        ]);

        $videoUrl = (string) ($payload['video_url'] ?? 'https://example.com/video-processing');
        $persistedVideoSource = $this->resolvePersistedVideoSource((string) ($payload['source'] ?? 'direct'));

        $aiSettings = [
            'enabled' => false,
            'auto_reply_enabled' => true,
            'assistant_name' => 'Webinar AI Helper',
            'generated_with_ai' => true,
            'generation_provider' => (string) ($payload['source'] ?? 'heygen'),
            'avatar_id' => (string) ($payload['avatar_id'] ?? ''),
            'voice_id' => (string) ($payload['voice_id'] ?? ''),
            'script' => $payload['script'],
            'intro_script' => (string) ($payload['intro_script'] ?? ''),
            'remaining_script' => (string) ($payload['remaining_script'] ?? ''),
            'slide_plan' => is_array($payload['slide_plan'] ?? null) ? $payload['slide_plan'] : [],
            'intro_duration_seconds' => (int) ($payload['intro_duration_seconds'] ?? 45),
            'heygen_video_id' => (string) ($payload['heygen_video_id'] ?? ''),
            'video_generation_status' => (string) ($payload['video_generation_status'] ?? ''),
            'brief' => [
                'webinar_type' => (string) ($payload['webinar_type'] ?? ''),
                'audience' => (string) ($payload['audience'] ?? ''),
                'goal' => (string) ($payload['goal'] ?? ''),
            ],
        ];

        if (! empty($payload['webinar_id'])) {
            $webinar = Webinar::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail((int) $payload['webinar_id']);

            $currentAiSettings = is_array($webinar->ai_settings) ? $webinar->ai_settings : [];
            $nextAiSettings = array_merge($currentAiSettings, $aiSettings);

            $update = [
                'title' => $payload['title'],
                'host_name' => $payload['host_name'],
                'description' => (string) ($payload['description'] ?? ''),
                'ai_settings' => $nextAiSettings,
            ];

            if (! empty($payload['video_url'])) {
                $update['video_url'] = $videoUrl;
                $update['video_source'] = $persistedVideoSource;
                $update['video_duration_seconds'] = $payload['video_duration_seconds'] ?? $webinar->video_duration_seconds;
            }

            $webinar->update($update);
        } else {
            $webinar = Webinar::create([
                'user_id' => $request->user()->id,
                'title' => $payload['title'],
                'title_prefix' => '[Confirmation]',
                'schedule_mode' => 'auto',
                'host_name' => $payload['host_name'],
                'description' => (string) ($payload['description'] ?? ''),
                'scheduled_at' => Carbon::now()->addDay(),
                'scheduled_timezone' => config('app.timezone', 'UTC'),
                'video_source' => $persistedVideoSource,
                'video_url' => $videoUrl,
                'video_duration_seconds' => $payload['video_duration_seconds'] ?? null,
                'thumbnail_path' => null,
                'min_viewers' => 80,
                'max_viewers' => 180,
                'is_published' => false,
                'email_settings' => [
                    'send_confirmation' => true,
                    'send_reminder' => true,
                    'send_follow_up' => true,
                    'auto_follow_up_profit_enabled' => true,
                ],
                'playback_settings' => [
                    'show_fake_viewers' => true,
                    'redirect_enabled' => false,
                    'redirect_url' => '',
                    'exit_popup_enabled' => false,
                    'exit_popup_heading' => '',
                    'exit_popup_body' => '',
                    'exit_popup_cta_text' => '',
                    'exit_popup_cta_url' => '',
                ],
                'registration_settings' => $this->defaultRegistrationSettings(),
                'ai_settings' => $aiSettings,
            ]);
        }

        return response()->json([
            'webinar_id' => $webinar->id,
            'edit_url' => route('admin.webinars.edit', $webinar),
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveVideoDimensions(string $ratio): array
    {
        return match ($ratio) {
            '9:16' => [1080, 1920],
            '1:1' => [1080, 1080],
            default => [1920, 1080],
        };
    }

    private function extractVideoId(array $responseJson): ?string
    {
        $value = $this->extractValue($responseJson, [
            'data.video_id',
            'video_id',
            'data.id',
            'id',
        ]);

        return $value !== null ? (string) $value : null;
    }

    /**
     * @param array<int, string> $paths
     */
    private function extractValue(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function uploadToCloudinary(string $videoUrl, string $videoId): ?string
    {
        $cloudName = (string) config('services.cloudinary.cloud_name', '');
        $apiKey = (string) config('services.cloudinary.api_key', '');
        $apiSecret = (string) config('services.cloudinary.api_secret', '');
        $uploadPreset = trim((string) config('services.cloudinary.upload_preset', ''));
        $notificationUrl = trim((string) config('services.cloudinary.notification_url', ''));
        $folder = trim((string) config('services.cloudinary.folder', 'webinars/heygen'));

        if ($cloudName === '') {
            return null;
        }

        $multipart = [
            [
                'name' => 'file',
                'contents' => $videoUrl,
            ],
            [
                'name' => 'public_id',
                'contents' => 'webinar-'.$videoId,
            ],
            [
                'name' => 'overwrite',
                'contents' => 'true',
            ],
            [
                'name' => 'resource_type',
                'contents' => 'video',
            ],
        ];

        if ($folder !== '') {
            $multipart[] = [
                'name' => 'folder',
                'contents' => $folder,
            ];
        }

        if ($notificationUrl !== '') {
            $multipart[] = [
                'name' => 'notification_url',
                'contents' => $notificationUrl,
            ];
        }

        if ($uploadPreset !== '') {
            $multipart[] = [
                'name' => 'upload_preset',
                'contents' => $uploadPreset,
            ];
        } else {
            if ($apiKey === '' || $apiSecret === '') {
                return null;
            }

            $timestamp = time();

            $signatureBase = "timestamp={$timestamp}";
            if ($folder !== '') {
                $signatureBase = "folder={$folder}&{$signatureBase}";
            }

            $signature = sha1($signatureBase.$apiSecret);

            $multipart[] = [
                'name' => 'api_key',
                'contents' => $apiKey,
            ];
            $multipart[] = [
                'name' => 'timestamp',
                'contents' => (string) $timestamp,
            ];
            $multipart[] = [
                'name' => 'signature',
                'contents' => $signature,
            ];
        }

        $endpoint = sprintf('https://api.cloudinary.com/v1_1/%s/video/upload', $cloudName);

        $response = Http::asMultipart()
            ->timeout(120)
            ->post($endpoint, $multipart);

        if (
            ! $response->successful()
            && $uploadPreset !== ''
            && $apiKey !== ''
            && $apiSecret !== ''
            && str_contains(strtolower((string) $response->body()), 'upload preset not found')
        ) {
            $timestamp = time();
            $signatureBase = "timestamp={$timestamp}";
            if ($folder !== '') {
                $signatureBase = "folder={$folder}&{$signatureBase}";
            }
            $signature = sha1($signatureBase.$apiSecret);

            $multipart = array_values(array_filter($multipart, fn (array $item): bool => ($item['name'] ?? '') !== 'upload_preset'));
            $multipart[] = ['name' => 'api_key', 'contents' => $apiKey];
            $multipart[] = ['name' => 'timestamp', 'contents' => (string) $timestamp];
            $multipart[] = ['name' => 'signature', 'contents' => $signature];

            $response = Http::asMultipart()
                ->timeout(120)
                ->post($endpoint, $multipart);
        }

        if (! $response->successful()) {
            Log::warning('Cloudinary upload failed for HeyGen fallback', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $secureUrl = data_get($response->json(), 'secure_url');

        return is_string($secureUrl) && $secureUrl !== '' ? $secureUrl : null;
    }

    private function generateOpenAiNarrationAudioUrl(string $script, string $voice): string
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $model = (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $ttsTimeoutSeconds = max(30, (int) env('OPENAI_TTS_TIMEOUT_SECONDS', 180));

        $response = Http::timeout($ttsTimeoutSeconds)
            ->withToken($apiKey)
            ->accept('audio/mpeg')
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $model,
                'voice' => $voice,
                'input' => $script,
                'format' => 'mp3',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'OpenAI TTS failed. status=%s body=%s',
                (string) $response->status(),
                substr((string) $response->body(), 0, 500),
            ));
        }

        $audioBinary = (string) $response->body();
        if ($audioBinary === '') {
            throw new \RuntimeException('OpenAI TTS returned empty audio.');
        }

        $audioUrl = $this->uploadAudioBinaryToCloudinary($audioBinary);
        if ($audioUrl === null) {
            throw new \RuntimeException('Failed to upload OpenAI narration audio.');
        }

        return $audioUrl;
    }

    private function uploadAudioBinaryToCloudinary(string $audioBinary): ?string
    {
        $cloudName = (string) config('services.cloudinary.cloud_name', '');
        $apiKey = (string) config('services.cloudinary.api_key', '');
        $apiSecret = (string) config('services.cloudinary.api_secret', '');
        $uploadPreset = trim((string) config('services.cloudinary.upload_preset', ''));
        $folder = trim((string) config('services.cloudinary.folder', 'webinars/heygen'));

        if ($cloudName === '') {
            return null;
        }

        $uploadFolder = $folder !== '' ? $folder.'/audio' : '';
        $publicId = 'webinar-audio-'.uniqid();
        $multipart = [
            [
                'name' => 'file',
                'contents' => $audioBinary,
                'filename' => $publicId.'.mp3',
                'headers' => [
                    'Content-Type' => 'audio/mpeg',
                ],
            ],
            [
                'name' => 'public_id',
                'contents' => $publicId,
            ],
            [
                'name' => 'overwrite',
                'contents' => 'true',
            ],
            [
                'name' => 'resource_type',
                'contents' => 'video',
            ],
        ];

        if ($uploadFolder !== '') {
            $multipart[] = [
                'name' => 'folder',
                'contents' => $uploadFolder,
            ];
        }

        if ($uploadPreset !== '') {
            $multipart[] = [
                'name' => 'upload_preset',
                'contents' => $uploadPreset,
            ];
        } else {
            if ($apiKey === '' || $apiSecret === '') {
                return null;
            }

            $timestamp = time();
            $signatureBase = "timestamp={$timestamp}";
            if ($uploadFolder !== '') {
                $signatureBase = "folder={$uploadFolder}&{$signatureBase}";
            }
            $signature = sha1($signatureBase.$apiSecret);

            $multipart[] = [
                'name' => 'api_key',
                'contents' => $apiKey,
            ];
            $multipart[] = [
                'name' => 'timestamp',
                'contents' => (string) $timestamp,
            ];
            $multipart[] = [
                'name' => 'signature',
                'contents' => $signature,
            ];
        }

        $endpoint = sprintf('https://api.cloudinary.com/v1_1/%s/video/upload', $cloudName);
        $response = Http::asMultipart()->timeout(120)->post($endpoint, $multipart);

        if (
            ! $response->successful()
            && $uploadPreset !== ''
            && $apiKey !== ''
            && $apiSecret !== ''
            && str_contains(strtolower((string) $response->body()), 'upload preset not found')
        ) {
            $timestamp = time();
            $signatureBase = "timestamp={$timestamp}";
            if ($uploadFolder !== '') {
                $signatureBase = "folder={$uploadFolder}&{$signatureBase}";
            }
            $signature = sha1($signatureBase.$apiSecret);

            $multipart = array_values(array_filter($multipart, fn (array $item): bool => ($item['name'] ?? '') !== 'upload_preset'));
            $multipart[] = ['name' => 'api_key', 'contents' => $apiKey];
            $multipart[] = ['name' => 'timestamp', 'contents' => (string) $timestamp];
            $multipart[] = ['name' => 'signature', 'contents' => $signature];

            $response = Http::asMultipart()->timeout(120)->post($endpoint, $multipart);
        }

        if (! $response->successful()) {
            Log::warning('Cloudinary upload failed for OpenAI narration audio', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        $secureUrl = data_get($response->json(), 'secure_url');

        return is_string($secureUrl) && $secureUrl !== '' ? $secureUrl : null;
    }

    private function resolveOrComposeLongFormVideo(string $videoId, string $introVideoUrl): ?string
    {
        $stateKey = $this->composeStateCacheKey($videoId);
        $state = Cache::get($stateKey);
        if (is_array($state) && ($state['status'] ?? null) === 'completed' && is_string($state['video_url'] ?? null)) {
            return (string) $state['video_url'];
        }

        if (is_array($state) && ($state['status'] ?? null) === 'processing') {
            return null;
        }

        $meta = Cache::get($this->composeMetaCacheKey($videoId));
        if (! is_array($meta)) {
            return $introVideoUrl;
        }

        $remainingScript = trim((string) ($meta['remaining_script'] ?? ''));
        if ($remainingScript === '') {
            return $introVideoUrl;
        }

        Cache::put($stateKey, ['status' => 'processing'], now()->addHours(6));

        try {
            $voice = trim((string) ($meta['openai_voice'] ?? 'alloy'));
            $slidePlan = is_array($meta['slide_plan'] ?? null) ? $meta['slide_plan'] : [];
            $aspectRatio = (string) ($meta['aspect_ratio'] ?? '16:9');
            $title = (string) ($meta['title'] ?? 'Webinar');

            $mergedUrl = $this->composeLongFormVideoFromIntroAndSlides(
                $videoId,
                $introVideoUrl,
                $remainingScript,
                $slidePlan,
                $voice,
                $aspectRatio,
                $title
            );

            if ($mergedUrl !== null) {
                Cache::put($stateKey, [
                    'status' => 'completed',
                    'video_url' => $mergedUrl,
                ], now()->addHours(24));

                return $mergedUrl;
            }

            Cache::put($stateKey, ['status' => 'failed'], now()->addHours(2));
            return $introVideoUrl;
        } catch (\Throwable $e) {
            Log::warning('webinar.ai.video.compose.failed', [
                'video_id' => $videoId,
                'message' => $e->getMessage(),
            ]);
            Cache::put($stateKey, ['status' => 'failed'], now()->addHours(2));

            return $introVideoUrl;
        }
    }

    /**
     * @param array<int, array{title?: string, bullets?: array<int, string>}> $slidePlan
     */
    private function composeLongFormVideoFromIntroAndSlides(
        string $videoId,
        string $introVideoUrl,
        string $remainingScript,
        array $slidePlan,
        string $voice,
        string $aspectRatio,
        string $title
    ): ?string {
        $tmpRoot = storage_path('app/tmp/webinar-ai');
        File::ensureDirectoryExists($tmpRoot);
        $workDir = $tmpRoot.'/compose-'.$videoId.'-'.time();
        File::ensureDirectoryExists($workDir);

        try {
            $introPath = $workDir.'/intro.mp4';
            $introResponse = Http::timeout(120)->get($introVideoUrl);
            if (! $introResponse->successful()) {
                throw new \RuntimeException('Failed to download intro video.');
            }
            File::put($introPath, $introResponse->body());

            $remainingAudioBinary = $this->generateOpenAiNarrationAudioBinary($remainingScript, $voice);
            $remainingAudioPath = $workDir.'/remaining.mp3';
            File::put($remainingAudioPath, $remainingAudioBinary);

            [$width, $height] = $this->resolveVideoDimensions($aspectRatio);
            $durationSeconds = max(20, (int) round((max(1, str_word_count($remainingScript)) / 130) * 60));

            $assPath = $workDir.'/slides.ass';
            File::put($assPath, $this->buildSlideAss($slidePlan, $durationSeconds, $title));

            $slidesPath = $workDir.'/slides.mp4';
            $ffmpeg = (string) env('FFMPEG_BIN', 'ffmpeg');
            $assArg = str_replace('\\', '/', $assPath);
            $baseFilter = sprintf(
                "drawbox=x=0:y=h-16:w='(t/%d)*w':h=16:color=0x6366f1@0.95:t=fill,drawbox=x=0:y=0:w=w:h=h:color=0x0b1020@0.22:t=fill,ass='%s'",
                max(1, $durationSeconds),
                $assArg
            );
            $slideCmd = sprintf(
                '%s -y -f lavfi -i %s -i %s -vf %s -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest %s',
                escapeshellarg($ffmpeg),
                escapeshellarg(sprintf('color=c=0x0f172a:s=%dx%d:d=%d', $width, $height, $durationSeconds)),
                escapeshellarg($remainingAudioPath),
                escapeshellarg($baseFilter),
                escapeshellarg($slidesPath)
            );
            $this->runShellCommand($slideCmd, 'Failed to render slide video.');

            $mergedPath = $workDir.'/merged.mp4';
            $concatCmd = sprintf(
                '%s -y -i %s -i %s -filter_complex %s -map %s -map %s -c:v libx264 -c:a aac -pix_fmt yuv420p %s',
                escapeshellarg($ffmpeg),
                escapeshellarg($introPath),
                escapeshellarg($slidesPath),
                escapeshellarg('[0:v:0][0:a:0][1:v:0][1:a:0]concat=n=2:v=1:a=1[v][a]'),
                escapeshellarg('[v]'),
                escapeshellarg('[a]'),
                escapeshellarg($mergedPath)
            );
            $this->runShellCommand($concatCmd, 'Failed to merge intro and slides video.');

            return $this->uploadLocalVideoFileToCloudinary($mergedPath, $videoId);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function generateOpenAiNarrationAudioBinary(string $script, string $voice): string
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $model = (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $ttsTimeoutSeconds = max(30, (int) env('OPENAI_TTS_TIMEOUT_SECONDS', 180));
        $response = Http::timeout($ttsTimeoutSeconds)
            ->withToken($apiKey)
            ->accept('audio/mpeg')
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $model,
                'voice' => $voice,
                'input' => $script,
                'format' => 'mp3',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI TTS failed for remaining script.');
        }

        $audioBinary = (string) $response->body();
        if ($audioBinary === '') {
            throw new \RuntimeException('OpenAI TTS returned empty audio for remaining script.');
        }

        return $audioBinary;
    }

    /**
     * @param array<int, array{title?: string, bullets?: array<int, string>}> $slidePlan
     */
    private function buildSlideAss(array $slidePlan, int $durationSeconds, string $title): string
    {
        $slides = collect($slidePlan)
            ->map(function ($slide): ?string {
                if (! is_array($slide)) {
                    return null;
                }
                $slideTitle = trim((string) ($slide['title'] ?? ''));
                $bullets = collect($slide['bullets'] ?? [])
                    ->map(fn ($item): string => trim((string) $item))
                    ->filter(fn (string $item): bool => $item !== '')
                    ->take(4)
                    ->values()
                    ->all();

                if ($slideTitle === '' && $bullets === []) {
                    return null;
                }

                $lines = [];
                if ($slideTitle !== '') {
                    $lines[] = '\b1'.$this->escapeAssText($slideTitle).'\b0';
                }
                foreach ($bullets as $bullet) {
                    $lines[] = '• '.$this->escapeAssText($bullet);
                }

                return implode('\N', $lines);
            })
            ->filter()
            ->values()
            ->all();

        if ($slides === []) {
            $slides = ['\b1'.$this->escapeAssText($title !== '' ? $title : 'Webinar').'\b0\N• Main concept\N• Practical steps\N• Next action'];
        }

        $count = max(1, count($slides));
        $chunk = max(5, (int) floor($durationSeconds / $count));
        $cursor = 0;
        $dialogues = [];

        foreach ($slides as $index => $text) {
            $start = $cursor;
            $end = min($durationSeconds, $cursor + $chunk);
            if ($index === $count - 1) {
                $end = $durationSeconds;
            }
            $dialogues[] = sprintf(
                'Dialogue: 0,%s,%s,SlideText,,0,0,0,,%s',
                $this->formatAssTime($start),
                $this->formatAssTime(max($start + 1, $end)),
                $text
            );
            $cursor = $end;
        }

        return implode("\n", [
            '[Script Info]',
            'ScriptType: v4.00+',
            'PlayResX: 1920',
            'PlayResY: 1080',
            'WrapStyle: 2',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name,Fontname,Fontsize,PrimaryColour,SecondaryColour,OutlineColour,BackColour,Bold,Italic,Underline,StrikeOut,ScaleX,ScaleY,Spacing,Angle,BorderStyle,Outline,Shadow,Alignment,MarginL,MarginR,MarginV,Encoding',
            'Style: SlideText,Arial,44,&H00FFFFFF,&H000000FF,&H00101820,&H7F000000,0,0,0,0,100,100,0,0,1,2.5,0,7,90,90,110,1',
            '',
            '[Events]',
            'Format: Layer,Start,End,Style,Name,MarginL,MarginR,MarginV,Effect,Text',
            ...$dialogues,
            '',
        ]);
    }

    private function formatAssTime(int $seconds): string
    {
        $sec = max(0, $seconds);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;

        return sprintf('%d:%02d:%02d.00', $h, $m, $s);
    }

    private function escapeAssText(string $text): string
    {
        $value = str_replace(['{', '}'], ['\{', '\}'], $text);
        $value = str_replace("\n", '\N', $value);

        return trim($value);
    }

    private function runShellCommand(string $command, string $errorMessage): void
    {
        $output = [];
        $exitCode = 0;
        exec($command.' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException($errorMessage.' '.implode("\n", array_slice($output, -8)));
        }
    }

    private function uploadLocalVideoFileToCloudinary(string $localFilePath, string $videoId): ?string
    {
        $cloudName = (string) config('services.cloudinary.cloud_name', '');
        $apiKey = (string) config('services.cloudinary.api_key', '');
        $apiSecret = (string) config('services.cloudinary.api_secret', '');
        $uploadPreset = trim((string) config('services.cloudinary.upload_preset', ''));
        $folder = trim((string) config('services.cloudinary.folder', 'webinars/heygen'));

        if ($cloudName === '' || ! File::exists($localFilePath)) {
            return null;
        }

        $endpoint = sprintf('https://api.cloudinary.com/v1_1/%s/video/upload', $cloudName);
        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($localFilePath, 'rb'),
                'filename' => 'webinar-'.$videoId.'-merged.mp4',
            ],
            [
                'name' => 'public_id',
                'contents' => 'webinar-'.$videoId.'-merged',
            ],
            [
                'name' => 'overwrite',
                'contents' => 'true',
            ],
            [
                'name' => 'resource_type',
                'contents' => 'video',
            ],
        ];

        if ($folder !== '') {
            $multipart[] = [
                'name' => 'folder',
                'contents' => $folder.'/merged',
            ];
        }

        if ($uploadPreset !== '') {
            $multipart[] = [
                'name' => 'upload_preset',
                'contents' => $uploadPreset,
            ];
        } else {
            if ($apiKey === '' || $apiSecret === '') {
                return null;
            }
            $timestamp = time();
            $signatureBase = "timestamp={$timestamp}";
            if ($folder !== '') {
                $signatureBase = 'folder='.$folder.'/merged&'.$signatureBase;
            }
            $signature = sha1($signatureBase.$apiSecret);
            $multipart[] = ['name' => 'api_key', 'contents' => $apiKey];
            $multipart[] = ['name' => 'timestamp', 'contents' => (string) $timestamp];
            $multipart[] = ['name' => 'signature', 'contents' => $signature];
        }

        $response = Http::asMultipart()->timeout(240)->post($endpoint, $multipart);
        if (
            ! $response->successful()
            && $uploadPreset !== ''
            && $apiKey !== ''
            && $apiSecret !== ''
            && str_contains(strtolower((string) $response->body()), 'upload preset not found')
        ) {
            $timestamp = time();
            $signatureBase = "timestamp={$timestamp}";
            if ($folder !== '') {
                $signatureBase = 'folder='.$folder.'/merged&'.$signatureBase;
            }
            $signature = sha1($signatureBase.$apiSecret);

            $multipart = array_values(array_filter($multipart, fn (array $item): bool => ($item['name'] ?? '') !== 'upload_preset'));
            $multipart[] = ['name' => 'api_key', 'contents' => $apiKey];
            $multipart[] = ['name' => 'timestamp', 'contents' => (string) $timestamp];
            $multipart[] = ['name' => 'signature', 'contents' => $signature];

            $response = Http::asMultipart()->timeout(240)->post($endpoint, $multipart);
        }

        if (! $response->successful()) {
            Log::warning('Cloudinary upload failed for merged webinar video', [
                'video_id' => $videoId,
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);

            return null;
        }

        $secureUrl = data_get($response->json(), 'secure_url');

        return is_string($secureUrl) && $secureUrl !== '' ? $secureUrl : null;
    }

    private function composeMetaCacheKey(string $videoId): string
    {
        return 'webinar:ai:compose:meta:'.$videoId;
    }

    private function composeStateCacheKey(string $videoId): string
    {
        return 'webinar:ai:compose:state:'.$videoId;
    }

    private function resolvePersistedVideoSource(string $source): string
    {
        // DB enum supports only youtube|vimeo|direct; AI internal states map to direct.
        return match (strtolower(trim($source))) {
            'youtube' => 'youtube',
            'vimeo' => 'vimeo',
            default => 'direct',
        };
    }

    /**
     * @return array{avatars: array<int, array<string, mixed>>, fetched_at: string, stale?: bool}
     */
    private function getHeygenAvatarOptionsPayload(string $apiKey): array
    {
        $cacheKey = 'webinar:ai:heygen:avatars:v1';
        $ttlSeconds = 60 * 60; // 1 hour
        $optionsLimit = max(10, min(200, (int) env('HEYGEN_OPTIONS_LIMIT', 50)));

        $payload = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($apiKey, $optionsLimit): array {
            $attempts = [
                [
                    'name' => 'v2_get',
                    'auth' => 'x-api-key',
                    'avatar' => ['GET', 'https://api.heygen.com/v2/avatars'],
                ],
                [
                    'name' => 'v1_get',
                    'auth' => 'x-api-key',
                    'avatar' => ['GET', 'https://api.heygen.com/v1/avatar.list'],
                ],
                [
                    'name' => 'v2_get_bearer',
                    'auth' => 'bearer',
                    'avatar' => ['GET', 'https://api.heygen.com/v2/avatars'],
                ],
            ];

            $avatarsResponse = null;

            foreach ($attempts as $attempt) {
                $headers = $this->heygenHeaders($apiKey, $attempt['auth']);
                [$avatarMethod, $avatarUrl] = $attempt['avatar'];
                $avatarsResponse = $this->sendHeygenRequest($avatarMethod, $avatarUrl, $headers);

                Log::info('webinar.ai.avatars.attempt', [
                    'attempt' => $attempt['name'],
                    'auth' => $attempt['auth'],
                    'avatars_status' => $avatarsResponse->status(),
                    'avatars_body' => substr((string) $avatarsResponse->body(), 0, 500),
                ]);

                if ($avatarsResponse->successful() || $this->isHeygenServiceUnavailable($avatarsResponse)) {
                    break;
                }
            }

            if ($avatarsResponse === null || ! $avatarsResponse->successful()) {
                throw new \RuntimeException(sprintf(
                    'HeyGen avatars fetch failed. avatars_status=%s avatars_body=%s',
                    (string) ($avatarsResponse?->status() ?? 'n/a'),
                    substr((string) ($avatarsResponse?->body() ?? ''), 0, 500),
                ));
            }

            $avatarsJson = $avatarsResponse->json();
            $avatarsRaw = data_get($avatarsJson, 'data.avatars',
                data_get($avatarsJson, 'data.avatar_list',
                    data_get($avatarsJson, 'data', [])
                )
            );

            $avatars = collect(is_array($avatarsRaw) ? $avatarsRaw : [])
                ->map(function ($item): array {
                    $row = is_array($item) ? $item : [];
                    $avatarId = (string) data_get($row, 'avatar_id', '');
                    $name = (string) data_get($row, 'name', data_get($row, 'avatar_name', $avatarId));
                    $preview = data_get($row, 'preview_image_url', data_get($row, 'preview_video_url'));
                    $gender = strtolower((string) data_get($row, 'gender', ''));

                    return [
                        'id' => $avatarId,
                        'name' => $name !== '' ? $name : $avatarId,
                        'preview_url' => is_string($preview) ? $preview : null,
                        'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
                        '_score' => $this->heygenAvatarPickerScore($row, $avatarId, $name),
                    ];
                })
                ->filter(fn (array $item): bool => $item['id'] !== '')
                ->sort(function (array $a, array $b): int {
                    $scoreCmp = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
                    if ($scoreCmp !== 0) {
                        return $scoreCmp;
                    }

                    return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                })
                ->take($optionsLimit)
                ->map(function (array $item): array {
                    unset($item['_score']);

                    return $item;
                })
                ->values()
                ->all();

            return [
                'avatars' => $avatars,
                'fetched_at' => now()->toIso8601String(),
            ];
        });

        Cache::put('webinar:ai:heygen:avatars:stale:v1', $payload, now()->addDay());

        return $payload;
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function openAiVoiceOptions(): array
    {
        return [
            ['id' => 'alloy', 'label' => 'Alloy'],
            ['id' => 'ash', 'label' => 'Ash'],
            ['id' => 'ballad', 'label' => 'Ballad'],
            ['id' => 'coral', 'label' => 'Coral'],
            ['id' => 'echo', 'label' => 'Echo'],
            ['id' => 'fable', 'label' => 'Fable'],
            ['id' => 'nova', 'label' => 'Nova'],
            ['id' => 'onyx', 'label' => 'Onyx'],
            ['id' => 'sage', 'label' => 'Sage'],
            ['id' => 'shimmer', 'label' => 'Shimmer'],
            ['id' => 'verse', 'label' => 'Verse'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitScriptForAvatarIntro(string $script, int $introDurationSeconds): array
    {
        $seconds = max(20, min(60, $introDurationSeconds));
        $targetWords = max(40, (int) round(($seconds / 60) * 130));
        $words = preg_split('/\s+/u', trim($script)) ?: [];

        if ($words === []) {
            return ['', ''];
        }

        $introWords = array_slice($words, 0, $targetWords);
        $remainingWords = array_slice($words, $targetWords);

        $introScript = trim(implode(' ', $introWords));
        $remainingScript = trim(implode(' ', $remainingWords));

        if ($remainingScript === '') {
            $fallbackIntroWords = max(20, (int) floor(count($words) * 0.3));
            $introWords = array_slice($words, 0, $fallbackIntroWords);
            $remainingWords = array_slice($words, $fallbackIntroWords);
            $introScript = trim(implode(' ', $introWords));
            $remainingScript = trim(implode(' ', $remainingWords));
        }

        return [$introScript, $remainingScript];
    }

    /**
     * @return array<int, array{title: string, bullets: array<int, string>}>
     */
    private function generateSlidePlan(string $remainingScript, string $title): array
    {
        $fallback = $this->fallbackSlidePlan($remainingScript, $title);
        if (trim($remainingScript) === '') {
            return $fallback;
        }

        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            return $fallback;
        }

        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        $prompt = implode("\n", [
            'Create a slide plan for webinar narration.',
            'Return strict JSON with this schema:',
            '{"slides":[{"title":"string","bullets":["string","string","string"]}]}',
            'Rules:',
            '- 6 to 12 slides',
            '- each slide has 2 to 5 concise bullets',
            '- no timestamps',
            '- no markdown',
            '- no camera directions',
            '- align with this webinar title: '.$title,
            '',
            'Narration:',
            $remainingScript,
        ]);

        try {
            $response = Http::timeout(90)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.3,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an expert webinar slide planner.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (! $response->successful()) {
                return $fallback;
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = json_decode($content, true);
            $slides = data_get($decoded, 'slides');
            if (! is_array($slides)) {
                return $fallback;
            }

            $normalized = collect($slides)
                ->map(function ($slide): ?array {
                    if (! is_array($slide)) {
                        return null;
                    }
                    $slideTitle = trim((string) ($slide['title'] ?? ''));
                    $bullets = collect($slide['bullets'] ?? [])
                        ->map(fn ($b): string => trim((string) $b))
                        ->filter(fn (string $b): bool => $b !== '')
                        ->take(5)
                        ->values()
                        ->all();

                    if ($slideTitle === '' || $bullets === []) {
                        return null;
                    }

                    return [
                        'title' => $slideTitle,
                        'bullets' => $bullets,
                    ];
                })
                ->filter()
                ->take(12)
                ->values()
                ->all();

            return $normalized !== [] ? $normalized : $fallback;
        } catch (\Throwable $e) {
            Log::warning('webinar.ai.slide_plan.failed', [
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return array<int, array{title: string, bullets: array<int, string>}>
     */
    private function fallbackSlidePlan(string $remainingScript, string $title): array
    {
        $paragraphs = preg_split('/\n{2,}/u', trim($remainingScript)) ?: [];
        $slides = [];

        foreach ($paragraphs as $index => $paragraph) {
            $sentences = preg_split('/(?<=[.!?])\s+/u', trim($paragraph)) ?: [];
            $bullets = collect($sentences)
                ->map(fn (string $s): string => trim($s))
                ->filter(fn (string $s): bool => $s !== '')
                ->take(4)
                ->values()
                ->all();

            if ($bullets === []) {
                continue;
            }

            $slides[] = [
                'title' => $index === 0 ? 'Core Strategy' : 'Key Point '.($index + 1),
                'bullets' => $bullets,
            ];

            if (count($slides) >= 10) {
                break;
            }
        }

        if ($slides === []) {
            $slides[] = [
                'title' => $title !== '' ? $title : 'Webinar Highlights',
                'bullets' => ['Main concept', 'Practical steps', 'Next action'],
            ];
        }

        return $slides;
    }

    /**
     * @return array{avatars: array<int, array<string, mixed>>, voices: array<int, array<string, mixed>>, fetched_at: string, stale?: bool}
     */
    private function getHeygenOptionsPayload(string $apiKey): array
    {
        $cacheKey = 'webinar:ai:heygen:options:v5';
        $ttlSeconds = 60 * 60; // 1 hour
        $optionsLimit = max(10, min(200, (int) env('HEYGEN_OPTIONS_LIMIT', 50)));

        $payload = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($apiKey, $optionsLimit): array {
            $attempts = [
                [
                    'name' => 'v2_get',
                    'auth' => 'x-api-key',
                    'avatar' => ['GET', 'https://api.heygen.com/v2/avatars'],
                    'voice' => ['GET', 'https://api.heygen.com/v2/voices'],
                ],
                [
                    'name' => 'v1_get',
                    'auth' => 'x-api-key',
                    'avatar' => ['GET', 'https://api.heygen.com/v1/avatar.list'],
                    'voice' => ['GET', 'https://api.heygen.com/v1/voice.list'],
                ],
                [
                    'name' => 'v2_get_bearer',
                    'auth' => 'bearer',
                    'avatar' => ['GET', 'https://api.heygen.com/v2/avatars'],
                    'voice' => ['GET', 'https://api.heygen.com/v2/voices'],
                ],
            ];

            $avatarsResponse = null;
            $voicesResponse = null;

            foreach ($attempts as $attempt) {
                $headers = $this->heygenHeaders($apiKey, $attempt['auth']);
                [$avatarMethod, $avatarUrl] = $attempt['avatar'];
                [$voiceMethod, $voiceUrl] = $attempt['voice'];

                $avatarsResponse = $this->sendHeygenRequest($avatarMethod, $avatarUrl, $headers);
                $voicesResponse = $this->sendHeygenRequest($voiceMethod, $voiceUrl, $headers);

                Log::info('webinar.ai.options.attempt', [
                    'attempt' => $attempt['name'],
                    'auth' => $attempt['auth'],
                    'avatars_status' => $avatarsResponse->status(),
                    'voices_status' => $voicesResponse->status(),
                    'avatars_body' => substr((string) $avatarsResponse->body(), 0, 500),
                    'voices_body' => substr((string) $voicesResponse->body(), 0, 500),
                ]);

                if ($avatarsResponse->successful() && $voicesResponse->successful()) {
                    break;
                }

                if ($this->isHeygenServiceUnavailable($avatarsResponse) || $this->isHeygenServiceUnavailable($voicesResponse)) {
                    break;
                }
            }

            if ($avatarsResponse === null || $voicesResponse === null || ! $avatarsResponse->successful() || ! $voicesResponse->successful()) {
                throw new \RuntimeException(sprintf(
                    'HeyGen options fetch failed. avatars_status=%s voices_status=%s avatars_body=%s voices_body=%s',
                    (string) ($avatarsResponse?->status() ?? 'n/a'),
                    (string) ($voicesResponse?->status() ?? 'n/a'),
                    substr((string) ($avatarsResponse?->body() ?? ''), 0, 500),
                    substr((string) ($voicesResponse?->body() ?? ''), 0, 500),
                ));
            }

            $avatarsJson = $avatarsResponse->json();
            $voicesJson = $voicesResponse->json();

            $avatarsRaw = data_get($avatarsJson, 'data.avatars',
                data_get($avatarsJson, 'data.avatar_list',
                    data_get($avatarsJson, 'data', [])
                )
            );

            $voicesRaw = data_get($voicesJson, 'data.voices',
                data_get($voicesJson, 'data.voice_list',
                    data_get($voicesJson, 'data', [])
                )
            );

            $avatars = collect(is_array($avatarsRaw) ? $avatarsRaw : [])
                ->map(function ($item): array {
                    $row = is_array($item) ? $item : [];
                    $avatarId = (string) data_get($row, 'avatar_id', '');
                    $name = (string) data_get($row, 'name', data_get($row, 'avatar_name', $avatarId));
                    $preview = data_get($row, 'preview_image_url', data_get($row, 'preview_video_url'));
                    $gender = strtolower((string) data_get($row, 'gender', ''));

                    return [
                        'id' => $avatarId,
                        'name' => $name !== '' ? $name : $avatarId,
                        'preview_url' => is_string($preview) ? $preview : null,
                        'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
                        '_score' => $this->heygenAvatarPickerScore($row, $avatarId, $name),
                    ];
                })
                ->filter(fn (array $item): bool => $item['id'] !== '')
                ->sort(function (array $a, array $b): int {
                    $scoreCmp = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
                    if ($scoreCmp !== 0) {
                        return $scoreCmp;
                    }

                    return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                })
                ->take($optionsLimit)
                ->map(function (array $item): array {
                    unset($item['_score']);

                    return $item;
                })
                ->values()
                ->all();

            $voices = collect(is_array($voicesRaw) ? $voicesRaw : [])
                ->map(function ($item): array {
                    $row = is_array($item) ? $item : [];
                    $voiceId = (string) data_get($row, 'voice_id', '');
                    $name = (string) data_get($row, 'name', $voiceId);
                    $language = (string) data_get($row, 'language', data_get($row, 'language_name', ''));
                    $previewAudio = data_get($row, 'preview_audio', data_get($row, 'preview_audio_url'));
                    $gender = strtolower((string) data_get($row, 'gender', ''));

                    return [
                        'id' => $voiceId,
                        'name' => $name !== '' ? $name : $voiceId,
                        'language' => $language,
                        'preview_audio' => is_string($previewAudio) ? $previewAudio : null,
                        'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
                        '_score' => $this->heygenVoicePickerScore($language, $previewAudio),
                    ];
                })
                ->filter(fn (array $item): bool => $item['id'] !== '')
                ->sort(function (array $a, array $b): int {
                    $scoreCmp = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
                    if ($scoreCmp !== 0) {
                        return $scoreCmp;
                    }

                    return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                })
                ->take($optionsLimit)
                ->map(function (array $item): array {
                    unset($item['_score']);

                    return $item;
                })
                ->values()
                ->all();

            return [
                'avatars' => $avatars,
                'voices' => $voices,
                'fetched_at' => now()->toIso8601String(),
            ];
        });

        // Keep a stale copy for graceful fallback during provider/network hiccups.
        Cache::put('webinar:ai:heygen:options:stale:v1', $payload, now()->addDay());

        return $payload;
    }

    private function resolveHeygenApiKey(): string
    {
        return trim((string) config('services.heygen.api_key', ''));
    }

    /**
     * @return array<string, string>
     */
    private function heygenHeaders(string $apiKey, string $authMode = 'x-api-key'): array
    {
        $headers = [
            'accept' => 'application/json',
        ];

        if ($authMode === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        } else {
            $headers['x-api-key'] = $apiKey;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendHeygenRequest(string $method, string $url, array $headers): Response
    {
        $optionsTimeoutSeconds = max(15, (int) env('HEYGEN_OPTIONS_TIMEOUT_SECONDS', 30));
        $optionsConnectTimeoutSeconds = max(5, (int) env('HEYGEN_OPTIONS_CONNECT_TIMEOUT_SECONDS', 10));

        $request = Http::timeout($optionsTimeoutSeconds)
            ->connectTimeout($optionsConnectTimeoutSeconds)
            ->withHeaders($headers);

        return match (strtoupper($method)) {
            'POST' => $request->post($url, []),
            default => $request->get($url),
        };
    }

    private function isHeygenServiceUnavailable(Response $response): bool
    {
        if ($response->status() < 500) {
            return false;
        }

        $body = strtolower((string) $response->body());

        return str_contains($body, 'internal_error')
            || str_contains($body, 'something is wrong')
            || str_contains($body, 'contact api@heygen.com');
    }

    /**
     * Prefer public-looking studio avatars and ones that read as "in scene" (office/desk/etc.).
     * HeyGen does not expose a dedicated "has background" flag on list payloads; we use heuristics.
     *
     * @param array<string, mixed> $item
     */
    private function heygenAvatarPickerScore(array $item, string $avatarId, string $name): int
    {
        $score = 0;
        $idLower = strtolower($avatarId);
        $nameLower = strtolower($name);

        if (str_contains($idLower, '_public') || str_contains($idLower, 'public')) {
            $score += 120;
        }

        $videoPreview = data_get($item, 'preview_video_url');
        if (is_string($videoPreview) && trim($videoPreview) !== '') {
            $score += 45;
        }

        $backgroundHints = [
            'office', 'desk', 'studio', 'room', 'sofa', 'couch', 'kitchen', 'library',
            'hallway', 'outdoor', 'street', 'living', 'bedroom', 'meeting', 'conference',
            'workspace', 'lobby', 'café', 'cafe', 'restaurant', 'classroom',
        ];

        foreach ($backgroundHints as $hint) {
            if (str_contains($nameLower, $hint) || str_contains($idLower, $hint)) {
                $score += 40;
                break;
            }
        }

        $tags = data_get($item, 'tags');
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $t = strtolower((string) $tag);
                if ($t === '') {
                    continue;
                }
                if (str_contains($t, 'studio') || str_contains($t, 'scene') || str_contains($t, 'full')) {
                    $score += 25;
                    break;
                }
            }
        }

        if (data_get($item, 'premium') === false) {
            $score += 8;
        }

        if (data_get($item, 'premium') === true) {
            $score -= 6;
        }

        return $score;
    }

    private function heygenVoicePickerScore(string $language, mixed $previewAudio): int
    {
        $score = 0;
        $lang = strtolower(trim($language));

        if ($lang !== '' && (str_contains($lang, 'english') || $lang === 'en' || str_starts_with($lang, 'en-') || str_starts_with($lang, 'en_'))) {
            $score += 120;
        }

        if (is_string($previewAudio) && trim($previewAudio) !== '') {
            $score += 55;
        }

        return $score;
    }

    private function sanitizeGeneratedNarration(string $script): string
    {
        $lines = preg_split('/\R/u', $script) ?: [];
        $cleaned = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $cleaned[] = '';
                continue;
            }

            // Remove common timestamp/scene/slide markers that cause TTS to read production artifacts.
            if (preg_match('/^\[?\s*\d{1,2}:\d{2}(?:\s*[-–]\s*\d{1,2}:\d{2})?\s*\]?[:\-]?\s*/u', $trimmed)) {
                continue;
            }
            if (preg_match('/^(section|scene|slide|timestamp|time|intro|outro)\s*\d*\s*[:\-]/iu', $trimmed)) {
                continue;
            }
            if (preg_match('/^\[[^\]]+\]$/u', $trimmed)) {
                continue;
            }

            $lineWithoutInlineDirections = preg_replace('/\[[^\]]+\]/u', '', $line);
            $lineWithoutInlineDirections = is_string($lineWithoutInlineDirections) ? trim($lineWithoutInlineDirections) : $trimmed;

            if ($lineWithoutInlineDirections !== '') {
                $cleaned[] = $lineWithoutInlineDirections;
            }
        }

        $text = trim(implode("\n", $cleaned));
        $text = str_ireplace(['[your name]', '(your name)', '{your name}'], '', $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim(is_string($text) ? $text : $script);
    }

    private function fingerprintKey(string $key): string
    {
        if ($key === '') {
            return 'empty';
        }

        return 'len:'.mb_strlen($key).' sha1:'.substr(sha1($key), 0, 12);
    }

    /**
     * @return array{buttons: array<int, array<string, mixed>>}
     */
    private function defaultRegistrationSettings(): array
    {
        return [
            'buttons' => [
                [
                    'label' => 'Join Webinar Now',
                    'enabled' => true,
                    'is_primary' => true,
                    'urgency_mode' => 'none',
                    'urgency_minutes' => null,
                ],
                [
                    'label' => 'Secure My Seat',
                    'enabled' => false,
                    'is_primary' => false,
                    'urgency_mode' => 'minutes',
                    'urgency_minutes' => 15,
                ],
                [
                    'label' => 'Join Live Session',
                    'enabled' => false,
                    'is_primary' => false,
                    'urgency_mode' => 'live',
                    'urgency_minutes' => null,
                ],
            ],
        ];
    }
}
