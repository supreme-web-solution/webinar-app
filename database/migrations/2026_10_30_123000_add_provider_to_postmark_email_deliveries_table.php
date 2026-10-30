<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postmark_email_deliveries', function (Blueprint $table) {
            $table->string('provider', 32)->default('postmark')->after('user_id');
            $table->text('status_detail')->nullable()->after('bounce_description');
        });

        Schema::table('postmark_email_deliveries', function (Blueprint $table) {
            $table->dropUnique(['postmark_message_id']);
            $table->unique(['provider', 'postmark_message_id'], 'email_deliveries_provider_message_unique');
        });
    }

    public function down(): void
    {
        Schema::table('postmark_email_deliveries', function (Blueprint $table) {
            $table->dropUnique('email_deliveries_provider_message_unique');
            $table->unique('postmark_message_id');
            $table->dropColumn(['provider', 'status_detail']);
        });
    }
};
