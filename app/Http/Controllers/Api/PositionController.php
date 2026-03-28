<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PositionController extends Controller
{
    /**
     * GET /api/positions
     * Return all positions with computed summary totals.
     */
    public function index()
    {
        $positions = auth()->user()->positions()->orderBy('value', 'desc')->get();

        $summary = [
            'total_value'       => round($positions->sum('value'), 2),
            'total_days_gain'   => round($positions->sum('days_gain_dollar'), 2),
            'total_gain'        => round($positions->sum('total_gain_dollar'), 2),
            'position_count'    => $positions->count(),
            'winners'           => $positions->where('total_gain_dollar', '>', 0)->count(),
            'losers'            => $positions->where('total_gain_dollar', '<', 0)->count(),
        ];

        return response()->json([
            'summary'   => $summary,
            'positions' => $positions,
        ]);
    }

    /**
     * POST /api/positions
     * Accept a single position OR an array of positions.
     */
    public function store(Request $request)
    {
        $data = $request->json()->all();

        // Support bulk import: if root is an array, process each item
        if (array_is_list($data)) {
            $created = [];
            foreach ($data as $item) {
                $created[] = $this->upsertPosition($item);
            }
            return response()->json([
                'message'   => count($created) . ' positions imported.',
                'positions' => $created,
            ], 201);
        }

        // Single position
        $position = $this->upsertPosition($data);
        return response()->json($position, 201);
    }

    /**
     * GET /api/positions/{id}
     */
    public function show(Position $position)
    {
        return response()->json($position);
    }

    /**
     * PUT /api/positions/{id}
     */
    public function update(Request $request, Position $position)
    {
        $position->update($request->all());
        return response()->json($position);
    }

    /**
     * DELETE /api/positions/{id}
     */
    public function destroy(Position $position)
    {
        $position->delete();
        return response()->json(['message' => 'Position deleted.']);
    }
/**
 * POST /api/positions/sell-recommendations
 * AI analysis focused on what to sell
 */
public function sellRecommendations()
{
    $positions = auth()->user()->positions()->orderBy('value', 'desc')->get();

    $apiKey = env('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        return response()->json([
            'message' => 'Anthropic API key missing (ANTHROPIC_API_KEY).',
        ], 422);
    }

    if ($positions->isEmpty()) {
        return response()->json(['error' => 'No positions found.'], 422);
    }

    $totalValue    = round($positions->sum('value'), 2);
    $totalGain     = round($positions->sum('total_gain_dollar'), 2);

    $positionLines = $positions->map(function ($p) {
        $status = $p->total_gain_dollar >= 0 ? 'UP' : 'DOWN';
        return "{$p->symbol} ({$p->asset_type}): qty={$p->quantity}, paid=\${$p->price_paid}, "
             . "now=\${$p->last_price}, value=\${$p->value}, "
             . "total_gain=\${$p->total_gain_dollar} ({$p->total_gain_percent}%) [{$status}], "
             . "today=\${$p->days_gain_dollar} ({$p->change_percent}%)";
    })->implode("\n");

        $prompt = <<<EOT
You are an aggressive but smart trading analyst. A trader just logged in and wants to know what they should sell RIGHT NOW based on technical analysis and risk management.

CRITICAL RULES (must follow):
1) Use ONLY the provided portfolio numbers as the numeric source of truth (price/value/P&L).
2) You do NOT have real-time charts/indicators unless they are provided. Do NOT invent RSI/MACD values.
3) When you recommend SELL/CONSIDER SELLING, you MUST include technical reasoning as either:
   - conditional checks ("If price broke support at X / if MACD crossed down / if RSI diverged...")
   - or qualitative trend logic tied to the provided numbers (downtrend vs cost basis, large drawdown, failed bounce).
4) If you reference support/resistance, explain what level to use (prior swing low/high) and phrase it as a level to check, not a guaranteed fact.

PORTFOLIO (Total Value: \${$totalValue}, Total P&L: \${$totalGain}):
{$positionLines}

Analyze this portfolio and give SELL recommendations only. Be direct and specific.

Format your response exactly like this:

🚨 SELL IMMEDIATELY
List any positions that should be sold right now with a one-line reason each. Focus on: down more than 50%, no recovery potential, better opportunities elsewhere. If none, say "None — hold your current positions."

⚠️ CONSIDER SELLING
List positions to consider selling with a one-line reason each. Focus on: weakening momentum, better to take profits, high risk/low reward. If none, say "None."

✅ STRONG HOLDS
List 2-3 positions that look strongest and should NOT be sold, with a one-line reason each.

💡 BOTTOM LINE
One paragraph summary: what's the most important action this trader should take today?

Be brutally honest. Use the actual dollar figures. Keep each line short and punchy.
EOT;

    $model = env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

    $response = Http::withHeaders([
        'x-api-key'         => $apiKey,
        'anthropic-version' => '2023-06-01',
        'content-type'      => 'application/json',
    ])->post('https://api.anthropic.com/v1/messages', [
        'model'      => $model,
        'max_tokens' => 1000,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ]);

    if (!$response->successful()) {
        return response()->json([
            'message' => 'Anthropic API request failed.',
            'status'  => $response->status(),
            'raw'     => $response->json(),
        ], 502);
    }

    $analysisText = $response->json('content.0.text');
    if (!$analysisText) {
        return response()->json([
            'message' => 'Anthropic API response missing expected content.',
            'raw'     => $response->json(),
        ], 502);
    }

    return response()->json([
        'analysis' => $analysisText,
    ]);
}
    /**
     * POST /api/positions/{position}/analyze
     * AI analysis of a single position.
     */
    public function analyzeOne(Position $position)
    {
        $apiKey = env('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            return response()->json([
                'message' => 'Anthropic API key missing (ANTHROPIC_API_KEY).',
            ], 422);
        }

        $prompt = <<<EOT
You are a professional trading analyst and options strategist. Analyze this single position and give specific, actionable advice.

CRITICAL RULES (must follow):
1) Use the provided POSITION numbers as the source of truth (especially Current Price and Current Value).
2) Do NOT replace the position with a different instrument or price source (example: do not use commodity spot prices like "silver per oz" unless the position explicitly says it is a spot commodity instrument).
3) Any price targets you give MUST be realistic relative to the provided Current Price and should be written in the same units.
4) If you lack real-time market data, say so and keep macro/world context qualitative only.
5) When you recommend SELL / TRIM / STOP / EXIT, include at least 2 technical reasons or technical checks. Because you do not have live charts here, phrase them as checks (examples: "if price broke key support", "if MACD bearish cross", "if RSI stays below 50", "if price is below declining 50/200 DMA", "if volume confirms breakdown"). Do NOT invent indicator values.

POSITION:
Symbol: {$position->symbol}
Asset Type: {$position->asset_type}
Quantity: {$position->quantity} shares
Average Cost: \${$position->price_paid}
Current Price: \${$position->last_price}
Current Value: \${$position->value}
Total Gain/Loss: \${$position->total_gain_dollar} ({$position->total_gain_percent}%)
Today's Change: \${$position->days_gain_dollar} ({$position->change_percent}%)
Option Type: {$position->option_type}
Strike Price: {$position->strike_price}
Expiration: {$position->expiration_date}

Provide analysis in these exact sections . Take into account the macro things happening in the world such as wars, economic brake downs, potential for bank failures , world affairs in general .

**📊 Position Assessment**
2-3 sentences on the current state of this position, performance vs cost basis.

**⚡ Short-Term Outlook (1-4 weeks)**
What to watch for, key price levels, momentum assessment.

**📈 Long-Term Outlook (3-12 months)**
Whether to hold, add, or exit based on the position's fundamentals and trend.

**💰 Price Targets**
- Hold if above: \$X
- Consider selling at: \$X
- Stop loss / cut losses at: \$X

**🎯 Covered Call Recommendation**
If this is a stock or ETF with 100+ shares, recommend a specific covered call strategy:
- Suggested expiration timeframe
- Suggested strike price range
- Expected premium range
- Risk/reward of selling the call
If covered calls don't apply, explain why.

**✅ Action Item**
One clear sentence: what should the trader do right now?

Keep it direct, specific, and use the actual dollar figures from the data.
EOT;

        $model = env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 1000,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Anthropic API request failed.',
                'status'  => $response->status(),
                'raw'     => $response->json(),
            ], 502);
        }

        $analysisText = $response->json('content.0.text');
        if (!$analysisText) {
            return response()->json([
                'message' => 'Anthropic API response missing expected content.',
                'raw'     => $response->json(),
            ], 502);
        }

        return response()->json([
            'position' => $position,
            'analysis' => $analysisText,
        ]);
    }

    /**
     * POST /api/positions/analyze
     * Send the entire portfolio to Claude for AI analysis.
     */
    public function analyze()
    {
        $positions = auth()->user()->positions()->orderBy('value', 'desc')->get();

        $apiKey = env('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            return response()->json([
                'message' => 'Anthropic API key missing (ANTHROPIC_API_KEY).',
            ], 422);
        }

        if ($positions->isEmpty()) {
            return response()->json(['error' => 'No positions found. Import your portfolio first.'], 422);
        }

        $totalValue    = round($positions->sum('value'), 2);
        $totalGain     = round($positions->sum('total_gain_dollar'), 2);
        $totalDaysGain = round($positions->sum('days_gain_dollar'), 2);
        $winners       = $positions->where('total_gain_dollar', '>', 0);
        $losers        = $positions->where('total_gain_dollar', '<', 0);

        $positionLines = $positions->map(function ($p) {
            $status = $p->total_gain_dollar >= 0 ? 'UP' : 'DOWN';
            return "{$p->symbol}: qty={$p->quantity}, paid=\${$p->price_paid}, "
                 . "now=\${$p->last_price}, value=\${$p->value}, "
                 . "total_gain=\${$p->total_gain_dollar} ({$p->total_gain_percent}%) [{$status}], "
                 . "today=\${$p->days_gain_dollar} ({$p->change_percent}%)";
        })->implode("\n");

        $prompt = <<<EOT
You are a professional portfolio analyst and trading coach. Analyze this stock/ETF portfolio objectively.

CRITICAL RULES (must follow):
1) Use ONLY the provided POSITIONS data as your numeric source of truth.
2) Do NOT invent current prices or substitute with outside spot prices.
3) If you provide targets/scenarios, keep them realistic relative to the provided "now" prices.
4) If you lack real-time market data, say so.

PORTFOLIO SNAPSHOT
Total Value: \${$totalValue}
Today's Gain: \${$totalDaysGain}
Total Gain/Loss: \${$totalGain}
Winners: {$winners->count()} | Losers: {$losers->count()}

POSITIONS:
{$positionLines}

Provide a structured analysis with these 4 sections:
1. **Portfolio Health** – overall assessment in 2-3 sentences.
2. **Top Risks** – identify the 2-3 biggest risk factors (concentration, underwater positions, volatility, etc.).
3. **Bright Spots** – highlight what's working and why it matters.
4. **Action Items** – 3 specific, actionable recommendations a trader should consider (not financial advice, just analysis).

Keep the tone direct and professional. Use dollar figures from the data.
EOT;

        $model = env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 800,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Anthropic API request failed.',
                'status'  => $response->status(),
                'raw'     => $response->json(),
            ], 502);
        }

        $analysisText = $response->json('content.0.text');
        if (!$analysisText) {
            return response()->json([
                'message' => 'Anthropic API response missing expected content.',
                'raw'     => $response->json(),
            ], 502);
        }

        return response()->json([
            'summary' => [
                'total_value'    => $totalValue,
                'total_gain'     => $totalGain,
                'today_gain'     => $totalDaysGain,
                'winners'        => $winners->count(),
                'losers'         => $losers->count(),
            ],
            'analysis' => $analysisText,
        ]);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function upsertPosition(array $data): Position
    {
        foreach (['change_percent', 'total_gain_percent'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = str_replace('%', '', $data[$field]);
            }
        }

        return Position::updateOrCreate(
            [
                'user_id'    => auth()->id(),
                'symbol'     => strtoupper($data['symbol']),
                'asset_type' => $data['asset_type'] ?? 'stock',
            ],
            [
                'last_price'        => $data['last_price']         ?? 0,
                'change_dollar'     => $data['change_dollar']      ?? 0,
                'change_percent'    => $data['change_percent']     ?? 0,
                'quantity'          => $data['quantity']           ?? 0,
                'price_paid'        => $data['price_paid']         ?? 0,
                'days_gain_dollar'  => $data['days_gain_dollar']   ?? 0,
                'total_gain_dollar' => $data['total_gain_dollar']  ?? 0,
                'total_gain_percent'=> $data['total_gain_percent'] ?? 0,
                'value'             => $data['value']              ?? 0,
                'option_type'       => $data['option_type']        ?? null,
                'strike_price'      => $data['strike_price']       ?? null,
                'expiration_date'   => $data['expiration_date']    ?? null,
                'underlying_symbol' => $data['underlying_symbol']  ?? null,
                'delta'             => $data['delta']              ?? null,
                'implied_volatility'=> $data['implied_volatility'] ?? null,
            ]
        );
    }
}