<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Models\WebinarView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebinarWatchTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_watched_to_end_is_rejected_when_duration_is_too_short(): void
    {
        [$webinar, $registrant, $view] = $this->createWatchSession(videoDurationSeconds: 3600);

        $response = $this->postJson("/webinar/{$registrant->access_token}/watch", [
            'milestone' => 'watched_to_end',
            'watch_duration_seconds' => 30,
            'completion_source' => 'timeline',
        ]);

        $response->assertStatus(422)->assertJson([
            'tracked' => false,
            'reason' => 'insufficient_watch_duration',
        ]);

        $registrant->refresh();
        $this->assertFalse($registrant->has_watched_to_end);
        $this->assertNull($view->fresh()->left_at);
    }

    public function test_embed_watched_to_end_is_accepted_after_minimum_watch_time(): void
    {
        [$webinar, $registrant, $view] = $this->createWatchSession(videoDurationSeconds: 3600);

        $response = $this->postJson("/webinar/{$registrant->access_token}/watch", [
            'milestone' => 'watched_to_end',
            'watch_duration_seconds' => 600,
            'completion_source' => 'embed_ended',
        ]);

        $response->assertOk()->assertJson(['tracked' => true]);

        $registrant->refresh();
        $this->assertTrue($registrant->has_watched_to_end);
        $this->assertNotNull($view->fresh()->left_at);
    }

    public function test_edit_stats_count_registrants_not_session_left_at(): void
    {
        $owner = User::factory()->create();
        $webinar = Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Stats Webinar',
            'host_name' => 'Host',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_duration_seconds' => 3600,
            'schedule_mode' => 'auto',
            'is_published' => true,
        ]);

        $completed = WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Completed Viewer',
            'email' => 'completed@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
            'max_watch_duration_seconds' => 3600,
            'has_watched_to_end' => true,
        ]);

        $leftEarly = WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Left Early',
            'email' => 'left@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
            'max_watch_duration_seconds' => 120,
            'has_watched_to_end' => false,
        ]);

        WebinarView::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $leftEarly->id,
            'joined_at' => now()->subMinutes(5),
            'session_started_at' => now()->subMinutes(5),
            'watch_duration_seconds' => 120,
            'left_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('admin.webinars.edit', $webinar))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('webinars/Edit')
                ->where('stats.views_60_seconds', 2)
                ->where('stats.views_watched_to_end', 1)
            );
    }

    /**
     * @return array{0: Webinar, 1: WebinarRegistrant, 2: WebinarView}
     */
    private function createWatchSession(int $videoDurationSeconds): array
    {
        $owner = User::factory()->create();
        $webinar = Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Watch Test',
            'host_name' => 'Host',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_duration_seconds' => $videoDurationSeconds,
            'schedule_mode' => 'auto',
            'is_published' => true,
        ]);

        $registrant = WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Viewer',
            'email' => 'viewer@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
        ]);

        $view = WebinarView::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'joined_at' => now(),
            'session_started_at' => now(),
            'watch_duration_seconds' => 0,
        ]);

        return [$webinar, $registrant, $view];
    }
}
