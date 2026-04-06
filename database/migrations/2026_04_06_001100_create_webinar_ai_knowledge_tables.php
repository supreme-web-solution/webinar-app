<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_ai_knowledge_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->string('title')->nullable();
            $table->string('source_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->longText('raw_text')->nullable();
            $table->string('status', 20)->default('queued');
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['webinar_id', 'status']);
            $table->index(['webinar_id', 'source_type']);
        });

        Schema::create('webinar_ai_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('webinar_ai_knowledge_sources')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->json('embedding')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'chunk_index']);
            $table->index(['webinar_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_ai_knowledge_chunks');
        Schema::dropIfExists('webinar_ai_knowledge_sources');
    }
};
