<?php

namespace Tests\Feature;

use App\Jobs\GenerateWebinarVideoTranscriptJob;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAiKnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebinarAiVideoTranscriptQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_video_transcript_job_using_existing_webinar_video_url(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $webinar = $this->createWebinar($owner, 'https://cdn.example.test/videos/webinar.mp4');

        $response = $this->actingAs($owner)->postJson(route('admin.webinars.ai.sources.transcript.video', [
            'webinar' => $webinar->id,
        ]), [
            'title' => 'Main Webinar Transcript',
        ]);

        $response->assertOk()
            ->assertJsonPath('video_url', 'https://cdn.example.test/videos/webinar.mp4');

        $source = WebinarAiKnowledgeSource::query()->where('webinar_id', $webinar->id)->first();

        $this->assertNotNull($source);
        $this->assertSame('video_transcript', $source->source_type);
        $this->assertSame('Main Webinar Transcript', $source->title);
        $this->assertSame('https://cdn.example.test/videos/webinar.mp4', $source->source_url);

        Queue::assertPushed(GenerateWebinarVideoTranscriptJob::class, function (GenerateWebinarVideoTranscriptJob $job) use ($source): bool {
            return $job->sourceId === $source->id;
        });
    }

    public function test_it_requires_video_url_when_missing_everywhere(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $webinar = $this->createWebinar($owner, '');

        $response = $this->actingAs($owner)->postJson(route('admin.webinars.ai.sources.transcript.video', [
            'webinar' => $webinar->id,
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Provide a video URL first in the Video step, then try again.');

        Queue::assertNothingPushed();
    }

    private function createWebinar(User $owner, string $videoUrl): Webinar
    {
        return Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Transcript Queue Webinar',
            'title_prefix' => '[Confirmation]',
            'schedule_mode' => 'auto',
            'host_name' => 'Host User',
            'description' => 'Test description',
            'video_source' => 'direct',
            'video_url' => $videoUrl,
            'slug' => Str::lower(Str::random(12)),
            'ai_settings' => [
                'enabled' => true,
                'auto_reply_enabled' => true,
                'assistant_name' => 'Webinar AI Helper',
            ],
            'is_published' => false,
        ]);
    }
}
