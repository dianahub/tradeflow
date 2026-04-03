<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AnthropicApiException;
use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\AI\AnthropicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortfolioImportController extends Controller
{
    public function __construct(private readonly AnthropicService $anthropic) {}

    public function importFromScreenshot(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240', // max 10MB
        ]);

        // Convert image to base64
        $image = $request->file('image');
        $imageData = base64_encode(file_get_contents($image->getRealPath()));
        $mimeType = $image->getMimeType();

        // Ask Claude to extract portfolio data from the image
        $prompt = <<<EOT
This is a screenshot of a brokerage portfolio or positions page.

Extract ALL positions you can see — including stocks, ETFs, crypto, AND options contracts. Do not skip any row.

For OPTIONS: look for rows with expiration dates, strike prices, or labels like "Call", "Put", "C", "P", or OCC-format symbols (e.g. "AAPL 01/17/25 $200 Call"). Set asset_type to "option".
For STOCKS/ETFs: set asset_type to "stock" or "etf".
For CRYPTO: set asset_type to "crypto".

Return a JSON array where each position has these fields (use null if not visible):
- symbol: the underlying ticker (e.g. "AAPL", not the full OCC string)
- asset_type: "stock", "etf", "option", or "crypto"
- quantity: number of shares, contracts, or units
- price_paid: average cost / cost basis per share or contract
- last_price: current price per share or contract
- value: current total market value
- change_dollar: today's dollar change
- change_percent: today's % change as a number (no % sign)
- days_gain_dollar: today's total gain/loss in dollars
- total_gain_dollar: total unrealized gain/loss in dollars
- total_gain_percent: total unrealized gain/loss % as a number (no % sign)
- option_type: "call" or "put" (options only, otherwise null)
- strike_price: strike price as a number (options only, otherwise null)
- expiration_date: expiration in YYYY-MM-DD format (options only, otherwise null)
- underlying_symbol: underlying ticker (options only, otherwise null)

Return ONLY a valid JSON array. No explanation, no markdown, no code blocks.
If you cannot read the image or find no positions, return an empty array: []
EOT;

        try {
            $text = $this->anthropic->completeWithVision($prompt, $imageData, $mimeType);
        } catch (AnthropicApiException $e) {
            return response()->json(['error' => 'Claude API error: ' . $e->getMessage()], 503);
        }

        // Strip markdown code fences Claude sometimes wraps around JSON
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', $text);
        Log::info('Screenshot import raw response', ['text' => $text]);

        // Parse the JSON response from Claude
        $positions = json_decode(trim($text), true);
        if (!is_array($positions)) {
            return response()->json(['error' => 'Could not parse positions from image', 'raw' => $text], 422);
        }

        if (empty($positions)) {
            return response()->json(['error' => 'No positions found in image'], 422);
        }

        // Clear existing positions before importing
        $userId = auth()->id();
        Position::where('user_id', $userId)->delete();

        $imported = [];

        foreach ($positions as $p) {
            if (empty($p['symbol'])) continue;

            // Clean percent fields
            foreach (['change_percent', 'total_gain_percent'] as $field) {
                if (isset($p[$field]) && is_string($p[$field])) {
                    $p[$field] = str_replace('%', '', $p[$field]);
                }
            }

            $assetType = $p['asset_type'] ?? 'stock';
            $uniqueKey = [
                'user_id'    => $userId,
                'symbol'     => strtoupper($p['symbol']),
                'asset_type' => $assetType,
            ];

            if ($assetType === 'option') {
                $uniqueKey['option_type']     = $p['option_type']     ?? null;
                $uniqueKey['strike_price']    = $p['strike_price']    ?? null;
                $uniqueKey['expiration_date'] = $p['expiration_date'] ?? null;
            }

            $position = Position::updateOrCreate(
                $uniqueKey,
                [
                    'last_price'        => $p['last_price']         ?? 0,
                    'change_dollar'     => $p['change_dollar']      ?? 0,
                    'change_percent'    => $p['change_percent']     ?? 0,
                    'quantity'          => $p['quantity']           ?? 0,
                    'price_paid'        => $p['price_paid']         ?? 0,
                    'days_gain_dollar'  => $p['days_gain_dollar']   ?? 0,
                    'total_gain_dollar' => $p['total_gain_dollar']  ?? 0,
                    'total_gain_percent'=> $p['total_gain_percent'] ?? 0,
                    'value'             => $p['value']              ?? 0,
                    'option_type'       => $p['option_type']        ?? null,
                    'strike_price'      => $p['strike_price']       ?? null,
                    'expiration_date'   => $p['expiration_date']    ?? null,
                    'underlying_symbol' => $p['underlying_symbol']  ?? null,
                ]
            );

            $imported[] = $position;
        }

        return response()->json([
            'message'   => count($imported) . ' positions imported from screenshot.',
            'positions' => $imported,
        ], 201);
    }
}