<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Support\Facades\Http;

class PriceController extends Controller
{
    /**
     * POST /api/prices/refresh-crypto
     * Refresh crypto prices via CoinGecko (unlimited, free)
     */
    public function refreshCrypto()
    {
        $user = auth()->user();
        $crypto = $user->positions()->where('asset_type', 'crypto')->get();

        if ($crypto->isEmpty()) {
            return response()->json(['message' => 'No crypto positions found.', 'updated' => 0]);
        }

        $updated = 0;
        $errors = [];

        $ids = $this->symbolsToIds($crypto->pluck('symbol')->toArray());

        $res = Http::get('https://api.coingecko.com/api/v3/simple/price', [
            'ids'                => $ids,
            'vs_currencies'      => 'usd',
            'include_24hr_change'=> 'true',
        ]);

        if ($res->ok()) {
            $prices = $res->json();
            foreach ($crypto as $position) {
                $id = $this->symbolToId($position->symbol);
                if (isset($prices[$id])) {
                    $newPrice     = $prices[$id]['usd'];
                    $change24h    = $prices[$id]['usd_24h_change'] ?? 0;
                    $newValue     = $newPrice * $position->quantity;
                    $totalGain    = ($newPrice - $position->price_paid) * $position->quantity;
                    $totalGainPct = $position->price_paid > 0
                        ? (($newPrice - $position->price_paid) / $position->price_paid) * 100
                        : 0;
                    $daysGain     = $newValue * ($change24h / 100);

                    $position->update([
                        'last_price'        => $newPrice,
                        'change_percent'    => round($change24h, 2),
                        'change_dollar'     => round($newPrice * ($change24h / 100), 6),
                        'days_gain_dollar'  => round($daysGain, 2),
                        'value'             => round($newValue, 2),
                        'total_gain_dollar' => round($totalGain, 2),
                        'total_gain_percent'=> round($totalGainPct, 2),
                    ]);
                    $updated++;
                } else {
                    $errors[] = "No CoinGecko data for {$position->symbol}";
                }
            }
        } else {
            $errors[] = 'CoinGecko API error';
        }

        return response()->json([
            'message' => "{$updated} crypto positions updated.",
            'updated' => $updated,
            'errors'  => $errors,
        ]);
    }

    /**
     * POST /api/prices/refresh-stocks
     * Refresh stock/ETF prices via Alpha Vantage (25 requests/day free)
     */
    public function refreshStocks()
    {
        $user = auth()->user();
        $stocks = $user->positions()->whereIn('asset_type', ['stock', 'etf'])->get();

        if ($stocks->isEmpty()) {
            return response()->json(['message' => 'No stock/ETF positions found.', 'updated' => 0]);
        }

        $updated = 0;
        $errors = [];
        $apiKey = env('ALPHA_VANTAGE_KEY', 'demo');

        foreach ($stocks as $position) {
            $res = Http::get('https://www.alphavantage.co/query', [
                'function' => 'GLOBAL_QUOTE',
                'symbol'   => $position->symbol,
                'apikey'   => $apiKey,
            ]);

            if ($res->ok()) {
                $quote = $res->json('Global Quote');
                if (!empty($quote) && isset($quote['05. price'])) {
                    $newPrice     = floatval($quote['05. price']);
                    $changeDollar = floatval($quote['09. change']);
                    $changePct    = floatval(str_replace('%', '', $quote['10. change percent']));
                    $newValue     = $newPrice * $position->quantity;
                    $totalGain    = ($newPrice - $position->price_paid) * $position->quantity;
                    $totalGainPct = $position->price_paid > 0
                        ? (($newPrice - $position->price_paid) / $position->price_paid) * 100
                        : 0;
                    $daysGain     = $changeDollar * $position->quantity;

                    $position->update([
                        'last_price'        => $newPrice,
                        'change_dollar'     => round($changeDollar, 4),
                        'change_percent'    => round($changePct, 2),
                        'days_gain_dollar'  => round($daysGain, 2),
                        'value'             => round($newValue, 2),
                        'total_gain_dollar' => round($totalGain, 2),
                        'total_gain_percent'=> round($totalGainPct, 2),
                    ]);
                    $updated++;
                } else {
                    $errors[] = "No data for {$position->symbol}";
                }
            }
            usleep(200000); // 0.2s delay between requests
        }

        return response()->json([
            'message' => "{$updated} stock/ETF positions updated.",
            'updated' => $updated,
            'errors'  => $errors,
        ]);
    }

    private function symbolToId(string $symbol): string
    {
        $map = [
            'BTC'  => 'bitcoin',
            'ETH'  => 'ethereum',
            'SOL'  => 'solana',
            'DOGE' => 'dogecoin',
            'ADA'  => 'cardano',
            'XRP'  => 'ripple',
            'DOT'  => 'polkadot',
            'AVAX' => 'avalanche-2',
            'MATIC'=> 'matic-network',
            'LINK' => 'chainlink',
            'LTC'  => 'litecoin',
            'UNI'  => 'uniswap',
            'ATOM' => 'cosmos',
            'NEAR' => 'near',
            'SHIB' => 'shiba-inu',
        ];
        return $map[strtoupper($symbol)] ?? strtolower($symbol);
    }

    private function symbolsToIds(array $symbols): string
    {
        return implode(',', array_map(fn($s) => $this->symbolToId($s), $symbols));
    }
}