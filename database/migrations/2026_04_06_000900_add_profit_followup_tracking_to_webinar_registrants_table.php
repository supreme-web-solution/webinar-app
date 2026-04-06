<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_registrants', function (Blueprint $table) {
            $table->unsignedInteger('max_watch_duration_seconds')->default(0)->after('follow_up_sent_at');
            $table->boolean('has_reached_50_percent')->default(false)->after('max_watch_duration_seconds');
            $table->boolean('has_watched_to_end')->default(false)->after('has_reached_50_percent');
            $table->boolean('has_offer_click')->default(false)->after('has_watched_to_end');
            $table->string('engagement_segment', 40)->nullable()->after('has_offer_click');
            $table->timestamp('engagement_segment_updated_at')->nullable()->after('engagement_segment');
            $table->timestamp('follow_up_lt_50_sent_at')->nullable()->after('engagement_segment_updated_at');
            $table->timestamp('follow_up_gte_50_sent_at')->nullable()->after('follow_up_lt_50_sent_at');
            $table->timestamp('follow_up_completed_no_click_sent_at')->nullable()->after('follow_up_gte_50_sent_at');

            $table->index(['webinar_id', 'engagement_segment'], 'webinar_registrants_engagement_idx');
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrants', function (Blueprint $table) {
            $table->dropIndex('webinar_registrants_engagement_idx');
            $table->dropColumn([
                'max_watch_duration_seconds',
                'has_reached_50_percent',
                'has_watched_to_end',
                'has_offer_click',
                'engagement_segment',
                'engagement_segment_updated_at',
                'follow_up_lt_50_sent_at',
                'follow_up_gte_50_sent_at',
                'follow_up_completed_no_click_sent_at',
            ]);
        });
    }
};
