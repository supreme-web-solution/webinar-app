<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinar_registrants', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('last_joined_at');
            $table->timestamp('follow_up_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrants', function (Blueprint $table) {
            $table->dropColumn(['reminder_sent_at', 'follow_up_sent_at']);
        });
    }
};
