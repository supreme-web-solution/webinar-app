<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->json('ai_settings')->nullable()->after('registration_settings');
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropColumn('ai_settings');
        });
    }
};
