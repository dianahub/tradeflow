<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('analysis_type');       // portfolio | position | sell_recommendations | journal_insights
            $table->string('subject_key');         // symbol for positions, 'portfolio' or 'journal' otherwise
            $table->string('context_hash', 64);    // SHA-256 of input data — used for cache lookup
            $table->text('prompt_summary');        // plain-English description of what went in — what gets embedded
            $table->longText('analysis_text');     // full AI response
            $table->longText('embedding')->nullable(); // JSON float array (1536 dims from text-embedding-3-small)
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->json('metadata')->nullable();  // snapshot of key numeric inputs
            $table->timestamps();

            $table->index(['user_id', 'analysis_type', 'created_at']);
            $table->index(['user_id', 'context_hash']);
            $table->index(['user_id', 'subject_key']);
        });

        Schema::create('ai_embedding_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | processing | done | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_embedding_jobs');
        Schema::dropIfExists('ai_analyses');
    }
};
