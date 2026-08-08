<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EngagedAudienceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_webinar_owner_can_export_clicked_registrants_csv(): void
    {
        $owner = User::factory()->create();
        $webinar = Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Export Webinar',
            'host_name' => 'Host',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_duration_seconds' => 3600,
            'schedule_mode' => 'auto',
            'is_published' => true,
        ]);

        $clicked = WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Clicked User',
            'email' => 'clicked@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
            'has_offer_click' => true,
            'engagement_segment' => 'above_50',
        ]);

        WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'No Click',
            'email' => 'noclick@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
        ]);

        $response = $this->actingAs($owner)->get(route('admin.webinars.attendees.export-clicked', ['webinar' => $webinar->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('clicked@example.test', $csv);
        $this->assertStringContainsString('Clicked User', $csv);
        $this->assertStringNotContainsString('noclick@example.test', $csv);
    }

    public function test_webinar_export_includes_analytics_event_clickers(): void
    {
        $owner = User::factory()->create();
        $webinar = Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Analytics Export Webinar',
            'host_name' => 'Host',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_duration_seconds' => 3600,
            'schedule_mode' => 'auto',
            'is_published' => true,
        ]);

        $registrant = WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Redirect Clicker',
            'email' => 'redirect@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
        ]);

        AnalyticsEvent::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'event_type' => 'webinar_redirect_triggered',
            'event_data' => [],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('admin.webinars.attendees.export-clicked', ['webinar' => $webinar->id]));

        $response->assertOk();
        $this->assertStringContainsString('redirect@example.test', $response->streamedContent());
    }

    public function test_campaign_owner_can_export_clicked_recipients_csv(): void
    {
        $owner = User::factory()->create([
            'email' => config('app.admin_emails')[0] ?? 'admin@example.test',
        ]);

        $campaign = EmailCampaign::query()->create([
            'user_id' => $owner->id,
            'title_prefix' => '[Campaign]',
            'title' => 'Export Campaign',
            'sender_name' => 'Host',
            'body' => '<p>Hello world</p>',
            'cta_label' => 'Open Link',
            'cta_url' => 'https://example.com/cta',
            'settings' => ['send_on_import' => false],
        ]);

        EmailCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Clicked Recipient',
            'email' => 'clicked@example.test',
            'access_token' => Str::random(40),
            'is_subscribed' => true,
            'imported_at' => now(),
            'click_count' => 2,
            'first_clicked_at' => now()->subHour(),
            'last_clicked_at' => now(),
        ]);

        EmailCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'No Click',
            'email' => 'noclick@example.test',
            'access_token' => Str::random(40),
            'is_subscribed' => true,
            'imported_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('admin.emails.attendees.export-clicked', ['campaign' => $campaign->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('clicked@example.test', $csv);
        $this->assertStringContainsString('Clicked Recipient', $csv);
        $this->assertStringNotContainsString('noclick@example.test', $csv);
    }

    public function test_other_user_cannot_export_webinar_clicked_registrants(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $webinar = Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Protected Webinar',
            'host_name' => 'Host',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_duration_seconds' => 3600,
            'schedule_mode' => 'auto',
            'is_published' => true,
        ]);

        $this->actingAs($other)
            ->get(route('admin.webinars.attendees.export-clicked', ['webinar' => $webinar->id]))
            ->assertForbidden();
    }
}
