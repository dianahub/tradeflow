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
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // e.g. 'portfolio_analysis'
            $table->string('label');                  // Human-readable label
            $table->string('description')->nullable(); // What this prompt does
            $table->longText('template');             // Current active template with {{PLACEHOLDERS}}
            $table->integer('version')->default(1);   // Current version number
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompts');
    }
};
