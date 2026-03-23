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

        if (!$apiKey || $apiKey === 'demo') {
            return response()->json([
                'message' => 'Alpha Vantage API key missing (ALPHA_VANTAGE_KEY). Stock prices were not refreshed.',
                'updated' => 0,
                'errors'  => ['Set ALPHA_VANTAGE_KEY in your backend environment.'],
            ], 422);
        }

        // Alpha Vantage free tier is rate-limited. Limit the number of requests per refresh.
        $maxPerRun = (int) env('ALPHA_VANTAGE_MAX_PER_REFRESH', 10);
        $requests = 0;

        foreach ($stocks as $position) {
            if ($requests >= $maxPerRun) {
                $errors[] = "Stopped after {$maxPerRun} requests to avoid Alpha Vantage rate limits.";
                break;
            }

            $rawSymbol = (string) $position->symbol;
            $symbol = strtoupper(trim($rawSymbol));
            $symbol = str_replace(' ', '', $symbol);

            // Skip symbols that are clearly not stock/ETF tickers (options descriptions, CUSIPs, notes, etc.)
            // Allow common ticker formats like BRK.B.
            if (!preg_match('/^[A-Z]{1,10}(\.[A-Z]{1,3})?$/', $symbol)) {
                $errors[] = "Unsupported symbol for stock refresh: {$rawSymbol}";
                continue;
            }

            $res = Http::get('https://www.alphavantage.co/query', [
                'function' => 'GLOBAL_QUOTE',
                'symbol'   => $symbol,
                'apikey'   => $apiKey,
            ]);

            if ($res->ok()) {
                $json = $res->json();

                // Rate limit / invalid call responses are not under "Global Quote".
                if (isset($json['Note'])) {
                    $errors[] = 'Alpha Vantage rate limit reached. Try again later.';
                    break;
                }
                if (isset($json['Information'])) {
                    $errors[] = 'Alpha Vantage daily limit reached. Try again tomorrow or use a different API key/plan.';
                    break;
                }
                if (isset($json['Error Message'])) {
                    $errors[] = "Alpha Vantage error for {$symbol}: " . $json['Error Message'];
                    continue;
                }

                $quote = $json['Global Quote'] ?? null;
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
                    $requests++;
                } else {
                    $errors[] = "No quote data for {$symbol}";
                    $requests++;
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

    /**
     * GET /api/prices/quote/{symbol}
     * Fetch a single stock/ETF quote (debug helper).
     */
    public function quote(string $symbol)
    {
        $apiKey = env('ALPHA_VANTAGE_KEY', 'demo');

        if (!$apiKey || $apiKey === 'demo') {
            return response()->json([
                'message' => 'Alpha Vantage API key missing (ALPHA_VANTAGE_KEY).',
            ], 422);
        }

        $rawSymbol = $symbol;
        $symbol = strtoupper(trim($symbol));
        $symbol = str_replace(' ', '', $symbol);

        if (!preg_match('/^[A-Z]{1,10}(\.[A-Z]{1,3})?$/', $symbol)) {
            return response()->json([
                'message' => "Unsupported symbol format: {$rawSymbol}",
            ], 422);
        }

        $res = Http::get('https://www.alphavantage.co/query', [
            'function' => 'GLOBAL_QUOTE',
            'symbol'   => $symbol,
            'apikey'   => $apiKey,
        ]);

        $json = $res->json();
        if (isset($json['Note'])) {
            return response()->json([
                'message' => 'Alpha Vantage rate limit reached. Try again later.',
                'raw'     => $json,
            ], 429);
        }
        if (isset($json['Information'])) {
            return response()->json([
                'message' => 'Alpha Vantage daily limit reached. Try again tomorrow or use a different API key/plan.',
                'raw'     => $json,
            ], 429);
        }
        if (isset($json['Error Message'])) {
            return response()->json([
                'message' => 'Alpha Vantage error.',
                'raw'     => $json,
            ], 422);
        }

        $quote = $json['Global Quote'] ?? null;
        $parsed = null;
        if (!empty($quote) && isset($quote['05. price'])) {
            $parsed = [
                'price'          => floatval($quote['05. price']),
                'change_dollar'  => floatval($quote['09. change'] ?? 0),
                'change_percent' => floatval(str_replace('%', '', ($quote['10. change percent'] ?? '0'))),
                'latest_trading_day' => $quote['07. latest trading day'] ?? null,
            ];
        }

        return response()->json([
            'symbol' => $symbol,
            'raw'    => $json,
            'quote'  => $quote,
            'parsed' => $parsed,
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