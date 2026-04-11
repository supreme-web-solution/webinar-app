<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebinarAiStudioController extends Controller
{
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

        $systemPrompt = 'You are a senior webinar scriptwriter. Write complete, long-form, production-ready webinar scripts.';

        $userPrompt = implode("\n", [
            'Create a long webinar script using the following settings:',
            'Topic: '.$payload['topic'],
            'Webinar type: '.$payload['webinar_type'],
            'Audience: '.$payload['audience'],
            'Primary goal: '.$payload['goal'],
            'Tone: '.$tone,
            'Language: '.$language,
            'Target duration in minutes: '.(int) $payload['duration_minutes'],
            '',
            'Output requirements:',
            '1) Return plain text only (no markdown).',
            '2) Include a title line and then section-by-section script with timestamps.',
            '3) Include opening hook, credibility section, core teaching blocks, transitions, objection handling, and clear CTA moments.',
            '4) Keep transitions natural for spoken delivery by a single presenter avatar.',
            '5) Include brief stage cues in square brackets only where useful (pause, emphasis, switch slide).',
            '6) Target approximately 130 spoken words per minute for pacing.',
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
            'voice_id' => ['nullable', 'string', 'max:255'],
            'aspect_ratio' => ['nullable', 'in:16:9,9:16,1:1'],
            'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $apiKey = (string) config('services.heygen.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'message' => 'HEYGEN_API_KEY is not configured.',
            ], 422);
        }

        [$width, $height] = $this->resolveVideoDimensions((string) ($payload['aspect_ratio'] ?? '16:9'));
        $background = (string) ($payload['background_color'] ?? '#F8FAFC');

        $videoInput = [
            'character' => [
                'type' => 'avatar',
                'avatar_id' => $payload['avatar_id'],
            ],
            'voice' => [
                'type' => 'text',
                'input_text' => $payload['script'],
            ],
            'background' => [
                'type' => 'color',
                'value' => $background,
            ],
        ];

        if (! empty($payload['voice_id'])) {
            $videoInput['voice']['voice_id'] = $payload['voice_id'];
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'accept' => 'application/json',
                ])
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
                return response()->json([
                    'message' => 'HeyGen rejected the generation request.',
                    'provider_status' => $response->status(),
                    'provider_response' => $response->json(),
                ], 502);
            }

            $responseJson = $response->json();
            $videoId = $this->extractVideoId($responseJson);

            if ($videoId === null) {
                return response()->json([
                    'message' => 'HeyGen did not return a video id.',
                    'provider_response' => $responseJson,
                ], 502);
            }

            return response()->json([
                'video_id' => $videoId,
                'status' => 'pending',
                'provider' => 'heygen',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webinar AI HeyGen generate failed', [
                'message' => $e->getMessage(),
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

        $apiKey = (string) config('services.heygen.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'message' => 'HEYGEN_API_KEY is not configured.',
            ], 422);
        }

        try {
            $statusResponse = Http::timeout(45)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'accept' => 'application/json',
                ])
                ->get('https://api.heygen.com/v1/video_status.get', [
                    'video_id' => $payload['video_id'],
                ]);

            if (! $statusResponse->successful()) {
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
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'accept' => 'application/json',
                    ])
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
                }
            }

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

            return response()->json([
                'video_id' => $payload['video_id'],
                'status' => $status,
                'video_url' => $videoUrl,
                'cloudinary_uploaded' => $cloudinaryUploaded,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webinar AI HeyGen status failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to check video status due to a server error.',
            ], 500);
        }
    }

    public function createWebinar(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'host_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'script' => ['required', 'string', 'min:300'],
            'video_url' => ['required', 'url', 'max:2048'],
            'video_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:80'],
            'avatar_id' => ['nullable', 'string', 'max:255'],
            'voice_id' => ['nullable', 'string', 'max:255'],
            'webinar_type' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'max:300'],
            'goal' => ['nullable', 'string', 'max:300'],
        ]);

        $webinar = Webinar::create([
            'user_id' => $request->user()->id,
            'title' => $payload['title'],
            'title_prefix' => '[Confirmation]',
            'schedule_mode' => 'auto',
            'host_name' => $payload['host_name'],
            'description' => (string) ($payload['description'] ?? ''),
            'scheduled_at' => Carbon::now()->addDay(),
            'scheduled_timezone' => config('app.timezone', 'UTC'),
            'video_source' => 'direct',
            'video_url' => $payload['video_url'],
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
            'ai_settings' => [
                'enabled' => false,
                'auto_reply_enabled' => true,
                'assistant_name' => 'Webinar AI Helper',
                'generated_with_ai' => true,
                'generation_provider' => (string) ($payload['source'] ?? 'heygen'),
                'avatar_id' => (string) ($payload['avatar_id'] ?? ''),
                'voice_id' => (string) ($payload['voice_id'] ?? ''),
                'script' => $payload['script'],
                'brief' => [
                    'webinar_type' => (string) ($payload['webinar_type'] ?? ''),
                    'audience' => (string) ($payload['audience'] ?? ''),
                    'goal' => (string) ($payload['goal'] ?? ''),
                ],
            ],
        ]);

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
