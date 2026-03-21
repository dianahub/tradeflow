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
    Schema::create('trades', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('symbol');
        $table->enum('asset_type', ['crypto', 'stock']);
        $table->enum('direction', ['long', 'short']);
        $table->decimal('entry_price', 15, 6);
        $table->decimal('exit_price', 15, 6)->nullable();
        $table->decimal('quantity', 15, 6);
        $table->decimal('fees', 10, 4)->default(0);
        $table->string('status')->default('open');
        $table->timestamp('opened_at');
        $table->timestamp('closed_at')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
