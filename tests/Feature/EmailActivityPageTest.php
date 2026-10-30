<?php

namespace Tests\Feature;

use App\Models\EmailActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailActivityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_email_activity_page(): void
    {
        $user = User::factory()->create();

        EmailActivityLog::query()->create([
            'user_id' => $user->id,
            'level' => 'info',
            'event' => 'test.event',
            'summary' => 'Test send completed',
        ]);

        $response = $this->actingAs($user)->get(route('admin.email-activity.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/email-activity/Index')
            ->has('logs', 1)
            ->where('logs.0.message', 'Test send completed')
            ->has('queue')
        );
    }

    public function test_guest_cannot_view_email_activity_page(): void
    {
        $this->get(route('admin.email-activity.index'))->assertRedirect();
    }
}
