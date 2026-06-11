<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->boolean('is_subscribed')->default(true)->after('access_token');
        });

        Schema::create('email_campaign_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('email_campaign_recipients')->nullOnDelete();
            $table->string('email');
            $table->string('token', 80)->unique();
            $table->timestamp('unsubscribed_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'email']);
            $table->unique('recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_unsubscribes');

        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('is_subscribed');
        });
    }
};
