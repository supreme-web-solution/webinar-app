<?php

namespace Tests\Feature;

use App\Jobs\GenerateWebinarAiReplyJob;
use App\Mail\WebinarAiNeedsAttentionMail;
use App\Models\ChatMessage;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistrant;
use App\Services\AI\WebinarAiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WebinarAiAutoReplyAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emails_owner_when_ai_flags_human_attention(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $webinar = $this->createWebinar($owner, [
            'enabled' => true,
            'auto_reply_enabled' => true,
            'assistant_name' => 'Support AI',
        ]);
        $registrant = $this->createRegistrant($webinar);

        $attendeeMessage = ChatMessage::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'attendee',
            'sender_name' => $registrant->name,
            'message' => 'Do you offer a refund if this does not work for me?',
            'is_automated' => false,
            'sent_at' => now(),
        ]);

        $assistant = Mockery::mock(WebinarAiAssistantService::class);
        $assistant->shouldReceive('maybeGenerateReply')->once()->andReturn([
            'answer' => 'I am an automated AI assistant, not a human host. I do not have enough verified information in this webinar knowledge base to answer that accurately. I have flagged your question so the webinar owner can review it and follow up with you.',
            'classification' => 'question',
            'sources' => ['Knowledge Source'],
            'needs_human_attention' => true,
            'attention_reason' => 'low_knowledge_confidence',
        ]);

        $job = new GenerateWebinarAiReplyJob($registrant->id, $attendeeMessage->id);
        $job->handle($assistant);

        $reply = ChatMessage::query()
            ->where('webinar_id', $webinar->id)
            ->where('registrant_id', $registrant->id)
            ->where('sender_type', 'system')
            ->where('is_automated', true)
            ->first();

        $this->assertNull($reply);

        Mail::assertSent(WebinarAiNeedsAttentionMail::class, function (WebinarAiNeedsAttentionMail $mail) use ($owner, $webinar, $registrant): bool {
            return $mail->hasTo($owner->email)
                && $mail->webinar->is($webinar)
                && $mail->registrant->is($registrant)
                && str_contains($mail->attendeeQuestion, 'refund');
        });
    }

    public function test_it_does_not_email_owner_for_normal_ai_reply(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner2@example.test']);
        $webinar = $this->createWebinar($owner, [
            'enabled' => true,
            'auto_reply_enabled' => true,
            'assistant_name' => 'Support AI',
        ]);
        $registrant = $this->createRegistrant($webinar);

        $attendeeMessage = ChatMessage::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'attendee',
            'sender_name' => $registrant->name,
            'message' => 'How long do I get access after purchase?',
            'is_automated' => false,
            'sent_at' => now(),
        ]);

        $assistant = Mockery::mock(WebinarAiAssistantService::class);
        $assistant->shouldReceive('maybeGenerateReply')->once()->andReturn([
            'answer' => 'You keep access for 12 months after purchase.',
            'classification' => 'question',
            'sources' => ['Offer Details PDF'],
            'needs_human_attention' => false,
        ]);

        $job = new GenerateWebinarAiReplyJob($registrant->id, $attendeeMessage->id);
        $job->handle($assistant);

        Mail::assertNothingSent();

        $reply = ChatMessage::query()
            ->where('webinar_id', $webinar->id)
            ->where('registrant_id', $registrant->id)
            ->where('sender_type', 'system')
            ->where('is_automated', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertSame('Support AI', $reply->sender_name);
    }

    public function test_it_uses_webinar_host_name_when_assistant_name_is_generic_default(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['email' => 'owner4@example.test']);
        $webinar = $this->createWebinar($owner, [
            'enabled' => true,
            'auto_reply_enabled' => true,
            'assistant_name' => 'Webinar AI Helper',
        ]);
        $registrant = $this->createRegistrant($webinar);

        $attendeeMessage = ChatMessage::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'attendee',
            'sender_name' => $registrant->name,
            'message' => 'When does this start?',
            'is_automated' => false,
            'sent_at' => now(),
        ]);

        $assistant = Mockery::mock(WebinarAiAssistantService::class);
        $assistant->shouldReceive('maybeGenerateReply')->once()->andReturn([
            'answer' => 'It starts at the scheduled time on your confirmation email.',
            'classification' => 'question',
            'sources' => ['Schedule FAQ'],
            'needs_human_attention' => false,
        ]);

        $job = new GenerateWebinarAiReplyJob($registrant->id, $attendeeMessage->id);
        $job->handle($assistant);

        $reply = ChatMessage::query()
            ->where('webinar_id', $webinar->id)
            ->where('registrant_id', $registrant->id)
            ->where('sender_type', 'system')
            ->where('is_automated', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertSame('Test Host', $reply->sender_name);
    }

    public function test_it_falls_back_to_configured_fallback_mailer_for_attention_email(): void
    {
        Mail::fake();

        config()->set('services.email.primary', 'not_a_real_mailer');
        config()->set('services.email.fallback', 'log');

        $owner = User::factory()->create(['email' => 'owner3@example.test']);
        $webinar = $this->createWebinar($owner, [
            'enabled' => true,
            'auto_reply_enabled' => true,
            'assistant_name' => 'Support AI',
        ]);
        $registrant = $this->createRegistrant($webinar);

        $attendeeMessage = ChatMessage::query()->create([
            'webinar_id' => $webinar->id,
            'registrant_id' => $registrant->id,
            'sender_type' => 'attendee',
            'sender_name' => $registrant->name,
            'message' => 'Can a human help with pricing?',
            'is_automated' => false,
            'sent_at' => now(),
        ]);

        $assistant = Mockery::mock(WebinarAiAssistantService::class);
        $assistant->shouldReceive('maybeGenerateReply')->once()->andReturn([
            'answer' => 'I have flagged this for the webinar owner to review.',
            'classification' => 'question',
            'sources' => [],
            'needs_human_attention' => true,
            'attention_reason' => 'low_knowledge_confidence',
        ]);

        $job = new GenerateWebinarAiReplyJob($registrant->id, $attendeeMessage->id);
        $job->handle($assistant);

        Mail::assertSent(WebinarAiNeedsAttentionMail::class, function (WebinarAiNeedsAttentionMail $mail) use ($owner): bool {
            return $mail->hasTo($owner->email);
        });
    }

    private function createWebinar(User $owner, array $aiSettings): Webinar
    {
        return Webinar::query()->create([
            'user_id' => $owner->id,
            'title' => 'Test Webinar',
            'title_prefix' => '[Confirmation]',
            'schedule_mode' => 'auto',
            'host_name' => 'Test Host',
            'description' => 'Test description',
            'video_source' => 'youtube',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'slug' => Str::lower(Str::random(12)),
            'ai_settings' => $aiSettings,
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    private function createRegistrant(Webinar $webinar): WebinarRegistrant
    {
        return WebinarRegistrant::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Jane Attendee',
            'email' => 'jane@example.test',
            'access_token' => Str::random(40),
            'registered_at' => now(),
            'is_subscribed' => true,
        ]);
    }
}
