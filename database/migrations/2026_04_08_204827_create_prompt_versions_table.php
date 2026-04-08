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
        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('prompt_key');             // Foreign key by key name
            $table->longText('template');             // Snapshot of the template at this version
            $table->integer('version');               // Version number
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_note')->nullable(); // Optional note about the change
            $table->timestamp('saved_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_versions');
    }
};
