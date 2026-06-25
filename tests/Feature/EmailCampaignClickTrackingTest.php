<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\EmailCampaignDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailCampaignClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_click_endpoint_records_click_and_redirects_to_cta_url(): void
    {
        [$campaign, $recipient] = $this->createCampaignWithRecipient();

        $response = $this->get(route('email.campaign.click', ['token' => $recipient->access_token]));

        $response->assertRedirect($campaign->cta_url);

        $this->assertDatabaseCount('email_campaign_clicks', 1);

        $recipient->refresh();
        $this->assertSame(1, $recipient->click_count);
        $this->assertNotNull($recipient->first_clicked_at);
        $this->assertNotNull($recipient->last_clicked_at);
    }

    public function test_campaign_email_body_links_use_tracking_url(): void
    {
        [$campaign, $recipient] = $this->createCampaignWithRecipient([
            'body' => '<p>Visit <a href="https://example.com/offer">our offer</a> today.</p>',
        ]);

        $html = $this->buildEmailHtml($campaign, $recipient);
        $trackUrl = route('email.campaign.click', ['token' => $recipient->access_token], absolute: true);

        $this->assertStringContainsString('href="'.$trackUrl.'"', $html);
        $this->assertStringNotContainsString('href="https://example.com/offer"', $html);
    }

    public function test_plain_text_urls_in_body_are_wrapped_with_tracking_url(): void
    {
        [$campaign, $recipient] = $this->createCampaignWithRecipient([
            'body' => 'Go to https://example.com/offer for details.',
        ]);

        $html = $this->buildEmailHtml($campaign, $recipient);
        $trackUrl = route('email.campaign.click', ['token' => $recipient->access_token], absolute: true);

        $this->assertStringContainsString('href="'.$trackUrl.'"', $html);
        $this->assertStringContainsString('https://example.com/offer', $html);
    }

    /**
     * @param  array<string, mixed>  $campaignOverrides
     * @return array{0: EmailCampaign, 1: EmailCampaignRecipient}
     */
    private function createCampaignWithRecipient(array $campaignOverrides = []): array
    {
        $user = User::factory()->create();

        $campaign = EmailCampaign::query()->create(array_merge([
            'user_id' => $user->id,
            'title_prefix' => '[Campaign]',
            'title' => 'Test Campaign',
            'sender_name' => 'Host',
            'body' => '<p>Hello world</p>',
            'cta_label' => 'Open Link',
            'cta_url' => 'https://example.com/cta',
            'settings' => ['send_on_import' => false],
        ], $campaignOverrides));

        $recipient = EmailCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Recipient',
            'email' => 'recipient@example.test',
            'access_token' => Str::random(40),
            'is_subscribed' => true,
            'imported_at' => now(),
        ]);

        return [$campaign, $recipient];
    }

    private function buildEmailHtml(EmailCampaign $campaign, EmailCampaignRecipient $recipient): string
    {
        $service = app(EmailCampaignDeliveryService::class);
        $method = new \ReflectionMethod($service, 'buildCampaignEmailHtml');

        return $method->invoke($service, $campaign, $recipient);
    }
}
