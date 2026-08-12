<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Models\Webinar;
use App\Rules\PersonDisplayName;
use App\Services\EmailCampaignDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PersonDisplayNameValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_display_name_rejects_email_addresses(): void
    {
        $validator = Validator::make(
            ['name' => 'admin@gmail.com'],
            ['name' => [new PersonDisplayName]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_person_display_name_rejects_urls(): void
    {
        $validator = Validator::make(
            ['name' => 'https://claw-aiarmy-prelaunch-webinar.netlify.app/'],
            ['name' => [new PersonDisplayName]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_person_display_name_accepts_plain_names(): void
    {
        $validator = Validator::make(
            ['name' => 'Domain Profits Team'],
            ['name' => [new PersonDisplayName]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_email_campaign_update_rejects_invalid_sender_name(): void
    {
        $user = User::factory()->create([
            'email' => config('app.admin_emails')[0] ?? 'admin@example.test',
        ]);

        $campaign = EmailCampaign::query()->create([
            'user_id' => $user->id,
            'title_prefix' => '[Campaign]',
            'title' => 'Validation Campaign',
            'sender_name' => 'Valid Sender',
            'body' => '<p>Hello</p>',
            'cta_label' => 'Open Link',
            'cta_url' => 'https://example.com/cta',
            'settings' => ['send_on_import' => false],
        ]);

        $response = $this->actingAs($user)->put(route('admin.emails.update', ['campaign' => $campaign->id]), [
            'title_prefix' => '[Campaign]',
            'title' => 'Validation Campaign',
            'sender_name' => 'https://example.com',
            'body' => '<p>Hello</p>',
            'cta_label' => 'Open Link',
            'cta_url' => 'https://example.com/cta',
            'settings' => ['send_on_import' => false],
        ]);

        $response->assertSessionHasErrors('sender_name');
    }

    public function test_webinar_update_rejects_invalid_host_name(): void
    {
        $user = User::factory()->create();

        $webinar = Webinar::query()->create([
            'user_id' => $user->id,
            'title' => 'Validation Webinar',
            'host_name' => 'Valid Host',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_duration_seconds' => 3600,
            'schedule_mode' => 'auto',
            'min_viewers' => 0,
            'max_viewers' => 100,
        ]);

        $response = $this->actingAs($user)->put(route('admin.webinars.update', ['webinar' => $webinar->id]), [
            'title' => 'Validation Webinar',
            'title_prefix' => '[Confirmation]',
            'schedule_mode' => 'auto',
            'host_name' => 'admin@gmail.com',
            'description' => '',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'min_viewers' => 0,
            'max_viewers' => 100,
        ]);

        $response->assertSessionHasErrors('host_name');
    }

    public function test_campaign_from_address_does_not_append_via_webinars(): void
    {
        $service = app(EmailCampaignDeliveryService::class);
        $method = new \ReflectionMethod($service, 'resolveDynamicFrom');

        $from = $method->invoke($service, 'Webinars <hello@domainprofits.site>', 'Domain Profits Team');

        $this->assertSame('Domain Profits Team <hello@domainprofits.site>', $from);
    }
}
