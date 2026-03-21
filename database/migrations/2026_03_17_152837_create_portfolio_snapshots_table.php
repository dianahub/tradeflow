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
    Schema::create('portfolio_snapshots', function (Blueprint $table) {
        $table->id();
        $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
        $table->decimal('total_value', 15, 2);
        $table->decimal('total_pnl', 15, 2);
        $table->decimal('total_pnl_percent', 8, 4);
        $table->json('allocation');
        $table->timestamp('recorded_at');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshots');
    }
};
