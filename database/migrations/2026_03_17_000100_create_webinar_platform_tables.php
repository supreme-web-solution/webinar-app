<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webinars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('host_name');
            $table->text('description')->nullable();
            $table->enum('video_source', ['youtube', 'vimeo', 'direct']);
            $table->string('video_url');
            $table->unsignedInteger('video_duration_seconds')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('slug')->unique();
            $table->unsignedInteger('min_viewers')->default(25);
            $table->unsignedInteger('max_viewers')->default(120);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->json('email_settings')->nullable();
            $table->json('playback_settings')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_published']);
        });

        Schema::create('webinar_registrants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('access_token', 80)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_joined_at')->nullable();
            $table->boolean('is_subscribed')->default(true);
            $table->timestamps();

            $table->unique(['webinar_id', 'email']);
            $table->index(['webinar_id', 'registered_at']);
        });

        Schema::create('webinar_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registrant_id')->nullable()->constrained('webinar_registrants')->nullOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('session_started_at')->nullable();
            $table->unsignedInteger('watch_duration_seconds')->default(0);
            $table->unsignedInteger('timeline_offset_seconds')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['webinar_id', 'joined_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registrant_id')->nullable()->constrained('webinar_registrants')->nullOnDelete();
            $table->enum('sender_type', ['host', 'attendee', 'system'])->default('attendee');
            $table->string('sender_name')->nullable();
            $table->text('message');
            $table->unsignedInteger('timeline_second')->nullable();
            $table->boolean('is_automated')->default(false);
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['webinar_id', 'timeline_second']);
        });

        Schema::create('webinar_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('trigger_second');
            $table->string('button_text')->default('Claim Offer');
            $table->string('button_url');
            $table->enum('display_mode', ['chat', 'popup', 'pinned'])->default('chat');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['webinar_id', 'trigger_second']);
        });

        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('trigger_second');
            $table->string('sender_name')->nullable();
            $table->text('message');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['webinar_id', 'trigger_second']);
        });

        Schema::create('email_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registrant_id')->nullable()->constrained('webinar_registrants')->nullOnDelete();
            $table->string('email');
            $table->string('token', 80)->unique();
            $table->timestamp('unsubscribed_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['webinar_id', 'email']);
            $table->unique('registrant_id');
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registrant_id')->nullable()->constrained('webinar_registrants')->nullOnDelete();
            $table->foreignId('view_id')->nullable()->constrained('webinar_views')->nullOnDelete();
            $table->string('event_type');
            $table->json('event_data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['webinar_id', 'event_type']);
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('email_unsubscribes');
        Schema::dropIfExists('scheduled_messages');
        Schema::dropIfExists('webinar_offers');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('webinar_views');
        Schema::dropIfExists('webinar_registrants');
        Schema::dropIfExists('webinars');
    }
};
