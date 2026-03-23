<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('webinars')
            ->whereNotNull('scheduled_at')
            ->update([
                'schedule_mode' => 'scheduled',
            ]);
    }

    public function down(): void
    {
        DB::table('webinars')
            ->whereNotNull('scheduled_at')
            ->where('schedule_mode', 'scheduled')
            ->update([
                'schedule_mode' => 'auto',
            ]);
    }
};
