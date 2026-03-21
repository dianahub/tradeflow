<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PortfolioImportController extends Controller
{
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

Extract ALL positions you can see and return them as a JSON array. 
For each position include these fields (use null if not visible):
- symbol (stock ticker, e.g. "AAPL")
- asset_type ("stock", "etf", "option", or "crypto")
- quantity (number of shares/units)
- price_paid (average cost or cost basis per share)
- last_price (current price per share)
- value (current total value)
- change_dollar (today's dollar change)
- change_percent (today's percent change, number only no % sign)
- days_gain_dollar (today's total gain in dollars)
- total_gain_dollar (total gain/loss in dollars)
- total_gain_percent (total gain/loss percent, number only no % sign)
- option_type ("call" or "put" if option, otherwise null)
- strike_price (if option, otherwise null)
- expiration_date (if option, format YYYY-MM-DD, otherwise null)
- underlying_symbol (if option, otherwise null)

Return ONLY a valid JSON array, no explanation, no markdown, no code blocks.
If you cannot read the image or find no positions, return an empty array: []
EOT;

        $response = Http::withHeaders([
            'x-api-key'         => env('ANTHROPIC_API_KEY'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-opus-4-6',
            'max_tokens' => 4096,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mimeType,
                                'data'       => $imageData,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]);

        if (!$response->ok()) {
            return response()->json(['error' => 'Claude API error'], 500);
        }

        $text = $response->json('content.0.text');

        // Parse the JSON response from Claude
        try {
            $positions = json_decode($text, true);
            if (!is_array($positions)) {
                return response()->json(['error' => 'Could not parse positions from image', 'raw' => $text], 422);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid JSON from Claude', 'raw' => $text], 422);
        }

        if (empty($positions)) {
            return response()->json(['error' => 'No positions found in image'], 422);
        }

        // Import the positions
        $userId = auth()->id();
        $imported = [];

        foreach ($positions as $p) {
            if (empty($p['symbol'])) continue;

            // Clean percent fields
            foreach (['change_percent', 'total_gain_percent'] as $field) {
                if (isset($p[$field]) && is_string($p[$field])) {
                    $p[$field] = str_replace('%', '', $p[$field]);
                }
            }

            $position = Position::updateOrCreate(
                [
                    'user_id'    => $userId,
                    'symbol'     => strtoupper($p['symbol']),
                    'asset_type' => $p['asset_type'] ?? 'stock',
                ],
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