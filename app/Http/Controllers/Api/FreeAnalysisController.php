<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreeAnalysisController extends Controller
{
    public function __construct(private readonly AnthropicService $anthropic) {}

    /**
     * POST /api/analyze-free
     *
     * Public endpoint for the Chrome extension — no auth required.
     * Rate-limited to 3 requests per IP per calendar day.
     */
    public function analyze(Request $request): JsonResponse
    {
        $positions = $request->input('positions');

        if (empty($positions) || !is_array($positions)) {
            return response()->json(['error' => 'positions array required'], 422);
        }

        if (count($positions) > 100) {
            return response()->json(['error' => 'too many positions (max 100)'], 422);
        }

        // Sanitise to prevent prompt injection
        $clean = array_values(array_filter(array_map(fn($p) => [
            'symbol'             => strtoupper(substr(preg_replace('/[^A-Z0-9.]/', '', strtoupper($p['symbol'] ?? '')), 0, 10)),
            'asset_type'         => in_array($p['asset_type'] ?? '', ['EQUITY', 'OPTION', 'MUTUAL_FUND']) ? $p['asset_type'] : 'EQUITY',
            'quantity'           => (float) ($p['quantity']           ?? 0),
            'price_paid'         => (float) ($p['price_paid']         ?? 0),
            'last_price'         => (float) ($p['last_price']         ?? 0),
            'value'              => (float) ($p['value']              ?? 0),
            'total_gain_dollar'  => (float) ($p['total_gain_dollar']  ?? 0),
            'total_gain_percent' => (float) ($p['total_gain_percent'] ?? 0),
            'days_gain_dollar'   => (float) ($p['days_gain_dollar']   ?? 0),
        ], $positions), fn($p) => $p['symbol'] !== ''));

        if (empty($clean)) {
            return response()->json(['error' => 'no valid positions'], 422);
        }

        try {
            $text = $this->anthropic->complete($this->buildPrompt($clean), maxTokens: 500);

            return response()->json(['analysis' => $text]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Analysis unavailable: ' . $e->getMessage()], 500);
        }
    }

    private function buildPrompt(array $positions): string
    {
        $totalValue = array_sum(array_column($positions, 'value'));
        $totalGain  = array_sum(array_column($positions, 'total_gain_dollar'));

        $lines = array_map(fn($p) =>
            "- {$p['symbol']} ({$p['asset_type']}): qty={$p['quantity']}, paid=\${$p['price_paid']}, now=\${$p['last_price']}, value=\${$p['value']}, total_gain=\${$p['total_gain_dollar']} ({$p['total_gain_percent']}%), today=\${$p['days_gain_dollar']}",
            $positions
        );

        $positionList = implode("\n", $lines);

        return <<<EOT
You are a professional portfolio manager. Review this portfolio and identify the SINGLE most important position to sell right now, if any.

PORTFOLIO (Total Value: \${$totalValue}, Total P&L: \${$totalGain}):
{$positionList}

Respond in this exact format — 3 lines maximum:

TOP SELL: [SYMBOL] — [one sentence reason using the actual numbers provided]

If there is nothing worth selling right now, respond with:

TOP SELL: Nothing to sell right now — [one sentence on what to watch]

Rules: Use ONLY the numbers provided. No RSI/MACD. Be direct and specific.
EOT;
    }
}
