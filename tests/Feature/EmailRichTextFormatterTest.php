<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\EmailCampaignDeliveryService;
use App\Services\EmailRichTextFormatter;
use App\Services\ResendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailRichTextFormatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preserves_blank_line_spacing_in_email_output(): void
    {
        $formatter = app(EmailRichTextFormatter::class);

        $input = '<p><strong>Hey there,</strong></p><p><br></p><p>Can you imagine what it feels like?</p>';
        $output = $formatter->formatForEmail($input);

        $this->assertStringContainsString('font-size:1px;line-height:14px', $output);
        $this->assertStringContainsString('<strong', $output);
        $this->assertStringContainsString('Hey there,', $output);
        $this->assertStringContainsString('Can you imagine what it feels like?', $output);
    }

    public function test_it_applies_inline_styles_for_email_clients(): void
    {
        $formatter = app(EmailRichTextFormatter::class);

        $output = $formatter->formatForEmail('<p>Line one</p><p><em>Line two</em></p>');

        $this->assertStringContainsString('<p style="margin:0 0 14px 0;color:#374151;font-size:15px;line-height:1.6;">Line one</p>', $output);
        $this->assertStringContainsString('<em style="font-style:italic;">Line two</em>', $output);
    }

    public function test_it_preserves_blank_lines_in_storage_html(): void
    {
        $formatter = app(EmailRichTextFormatter::class);

        $output = $formatter->sanitizeForStorage('<p>Hello</p><p><br></p><p><a href="javascript:alert(1)">Bad</a></p>');

        $this->assertSame('<p>Hello</p><p><br></p><p><a>Bad</a></p>', $output);
    }

    public function test_plain_text_blank_lines_render_as_email_spacers(): void
    {
        $formatter = app(EmailRichTextFormatter::class);

        $output = $formatter->formatPlainTextForEmail("Line one\n\nLine two");

        $this->assertStringContainsString('Line one', $output);
        $this->assertStringContainsString('Line two', $output);
        $this->assertStringContainsString('font-size:1px;line-height:14px', $output);
    }

    public function test_campaign_email_body_receives_inline_styles(): void
    {
        $user = User::factory()->create();

        $campaign = EmailCampaign::query()->create([
            'user_id' => $user->id,
            'title_prefix' => '[Campaign]',
            'title' => 'Test Campaign',
            'sender_name' => 'Host',
            'body' => '<p>Visit <a href="https://example.com/offer">our offer</a> today.</p><p><br></p><p><strong>Thanks</strong></p>',
            'cta_label' => 'Open Link',
            'cta_url' => 'https://example.com/cta',
            'settings' => ['send_on_import' => false],
        ]);

        $recipient = EmailCampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Recipient',
            'email' => 'recipient@example.test',
            'access_token' => Str::random(40),
            'is_subscribed' => true,
            'imported_at' => now(),
        ]);

        $service = app(EmailCampaignDeliveryService::class);
        $method = new \ReflectionMethod($service, 'buildCampaignEmailHtml');
        $html = $method->invoke($service, $campaign, $recipient);

        $this->assertStringContainsString('style="color:#2563eb;text-decoration:underline;word-break:break-word;"', $html);
        $this->assertStringContainsString('style="font-weight:700;"', $html);
        $this->assertStringContainsString('font-size:1px;line-height:14px', $html);
    }

    public function test_resend_service_formats_webinar_description_for_email(): void
    {
        $method = new \ReflectionMethod(ResendService::class, 'formatDescriptionForEmail');
        $method->setAccessible(true);

        $html = $method->invoke(
            app(ResendService::class),
            '<p>Intro</p><p><br></p><p><strong>Bold line</strong></p>',
        );

        $this->assertStringContainsString('style="font-weight:700;"', $html);
        $this->assertStringContainsString('font-size:1px;line-height:14px', $html);
        $this->assertStringContainsString('Intro', $html);
        $this->assertStringContainsString('Bold line', $html);
    }

    public function test_resend_service_formats_plain_text_intro_with_blank_lines(): void
    {
        $method = new \ReflectionMethod(ResendService::class, 'formatIntroForEmail');
        $method->setAccessible(true);

        $html = $method->invoke(
            app(ResendService::class),
            "First paragraph\n\nSecond paragraph",
        );

        $this->assertStringContainsString('First paragraph', $html);
        $this->assertStringContainsString('Second paragraph', $html);
        $this->assertStringContainsString('font-size:1px;line-height:14px', $html);
    }
}
