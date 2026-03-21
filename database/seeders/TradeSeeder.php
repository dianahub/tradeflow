<?php

namespace Database\Seeders;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Seeder;

class TradeSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::find(5); // your diana5 user

        $trades = [
            // BTC longs — mixed results
            ['symbol' => 'BTC', 'direction' => 'long', 'entry_price' => 42000, 'exit_price' => 46000, 'quantity' => 0.5, 'fees' => 12],
            ['symbol' => 'BTC', 'direction' => 'long', 'entry_price' => 48000, 'exit_price' => 45000, 'quantity' => 0.3, 'fees' => 10],
            ['symbol' => 'BTC', 'direction' => 'long', 'entry_price' => 44000, 'exit_price' => 47500, 'quantity' => 0.4, 'fees' => 11],
            // ETH trades
            ['symbol' => 'ETH', 'direction' => 'long', 'entry_price' => 2800, 'exit_price' => 3100, 'quantity' => 2,   'fees' => 8],
            ['symbol' => 'ETH', 'direction' => 'long', 'entry_price' => 3200, 'exit_price' => 2900, 'quantity' => 1.5, 'fees' => 7],
            // AAPL stocks
            ['symbol' => 'AAPL', 'direction' => 'long', 'entry_price' => 175, 'exit_price' => 182, 'quantity' => 10,  'fees' => 1],
            ['symbol' => 'AAPL', 'direction' => 'long', 'entry_price' => 180, 'exit_price' => 178, 'quantity' => 5,   'fees' => 1],
            // Short trade
            ['symbol' => 'ETH', 'direction' => 'short', 'entry_price' => 3300, 'exit_price' => 3100, 'quantity' => 1, 'fees' => 9],
        ];

        foreach ($trades as $trade) {
            $user->trades()->create([
                'symbol'      => $trade['symbol'],
                'asset_type'  => in_array($trade['symbol'], ['BTC', 'ETH']) ? 'crypto' : 'stock',
                'direction'   => $trade['direction'],
                'entry_price' => $trade['entry_price'],
                'exit_price'  => $trade['exit_price'],
                'quantity'    => $trade['quantity'],
                'fees'        => $trade['fees'],
                'status'      => 'closed',
                'opened_at'   => now()->subDays(rand(1, 30)),
                'closed_at'   => now()->subDays(rand(0, 5)),
            ]);
        }
    }
}