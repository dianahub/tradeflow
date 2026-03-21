<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            // Core fields (all position types)
            $table->string('symbol');
            $table->string('asset_type')->default('stock'); // stock, etf, option, crypto
            $table->decimal('last_price', 15, 6);
            $table->decimal('change_dollar', 15, 6)->default(0);
            $table->decimal('change_percent', 10, 4)->default(0);
            $table->decimal('quantity', 15, 6);
            $table->decimal('price_paid', 15, 6);
            $table->decimal('days_gain_dollar', 15, 6)->default(0);
            $table->decimal('total_gain_dollar', 15, 6)->default(0);
            $table->decimal('total_gain_percent', 10, 4)->default(0);
            $table->decimal('value', 15, 6)->default(0);

            // Options-only fields (null for stocks/etfs/crypto)
            $table->string('option_type')->nullable();              // call or put
            $table->decimal('strike_price', 15, 6)->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('underlying_symbol')->nullable();        // e.g. SPY for a SPY option
            $table->decimal('delta', 8, 4)->nullable();             // options greek
            $table->decimal('implied_volatility', 8, 4)->nullable(); // IV %

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
