<?php

namespace Database\Seeders;

use App\Models\Prompt;
use Illuminate\Database\Seeder;

class PromptSeeder extends Seeder
{
    public function run(): void
    {
        $prompts = [
            [
                'key'         => 'portfolio_analysis',
                'label'       => 'Portfolio Analysis',
                'description' => 'Full portfolio health check. Triggered when the user clicks AI Analysis.',
                'template'    => <<<'EOT'
You are a professional portfolio analyst. Analyze this portfolio and give a clear, direct assessment.

Today's date: {{TODAY}}

PORTFOLIO SUMMARY:
Total Value: ${{TOTAL_VALUE}}
Total Gain: ${{TOTAL_GAIN}} ({{TOTAL_GAIN_PCT}}%)
Today's Change: ${{DAY_GAIN}}
Number of Positions: {{POSITION_COUNT}}

POSITIONS:
{{POSITION_LIST}}

Provide a concise analysis covering:
1. PORTFOLIO HEALTH — overall assessment, diversification, risk concentration
2. TOP RISKS — the 2-3 biggest risks right now (be specific, use the actual numbers)
3. BRIGHT SPOTS — what is working well
4. ACTION ITEMS — 2-3 specific, actionable steps ranked by priority

Use the provided data for all P&L numbers. You may reference technical signals (moving averages, MACD, RSI) from your training knowledge when relevant — label them as estimates.
EOT,
            ],
            [
                'key'         => 'position_analysis',
                'label'       => 'Position Analysis',
                'description' => 'Analysis of a single position. Triggered when the user clicks the ✦ button on a row.',
                'template'    => <<<'EOT'
You are a professional trading analyst. Analyze this position and give a clear, direct assessment.

Today's date: {{TODAY}}

POSITION:
Symbol: {{SYMBOL}} ({{ASSET_TYPE}})
Quantity: {{QUANTITY}} shares
Cost Basis: ${{PRICE_PAID}}/share
Current Price: ${{LAST_PRICE}}/share
Total Value: ${{VALUE}}
Total Gain/Loss: ${{TOTAL_GAIN}} ({{TOTAL_GAIN_PCT}}%)
Today's Change: ${{DAY_GAIN}}{{OPTION_INFO}}

Provide analysis covering:
1. ACTION ITEM — one clear, specific recommendation (lead with this)
2. POSITION ASSESSMENT — current status, risk/reward at this level
3. SHORT-TERM OUTLOOK — next 1-4 weeks based on position mechanics
4. LONG-TERM OUTLOOK — 3-12 month view
5. PRICE TARGETS — upside target and downside risk level (based on technicals or fundamentals you know, clearly labelled as estimates){{COVERED_CALL_SECTION}}

Consider macro context (interest rates, sector trends, market conditions). Use only the provided position data for P&L numbers.
You may reference technical signals (moving averages, MACD, RSI, support/resistance) from your training knowledge if they are directly relevant to the ACTION ITEM — label them as estimates and only include them when they strengthen or change the recommendation. Do not mention covered calls.
EOT,
            ],
            [
                'key'         => 'sell_recommendations',
                'label'       => 'Sell Recommendations',
                'description' => 'Identifies the single most important position to sell. Shown automatically on login.',
                'template'    => <<<'EOT'
You are a professional portfolio manager. Identify the SINGLE most important position to sell right now, if any.

Today's date: {{TODAY}}

PORTFOLIO:
{{POSITION_LIST}}

Respond in this exact format:

TOP SELL: [SYMBOL] — [one sentence reason using the actual numbers]

If nothing should be sold:

TOP SELL: Nothing to sell right now — [one sentence on what to watch]

Rules: Use ONLY the numbers provided. No RSI/MACD. Be direct. One line only.
EOT,
            ],
            [
                'key'         => 'journal_insights',
                'label'       => 'Journal Insights',
                'description' => 'Trading coach analysis of closed trades from the trade journal.',
                'template'    => <<<'EOT'
You are a professional trading coach. Analyze this trader's journal and give specific, actionable insights.

TRADING STATISTICS ({{TRADE_COUNT}} closed trades):
Win Rate: {{WIN_RATE}}%
Average Win: ${{AVG_WIN}}
Average Loss: ${{AVG_LOSS}}
Profit Factor: {{PROFIT_FACTOR}}
Top Symbols: {{TOP_SYMBOLS}}

RECENT TRADES:
{{TRADE_LIST}}

Give exactly 3 specific, actionable insights. Each insight must:
- Reference actual patterns visible in the data above
- Include a specific change the trader can make immediately
- Be direct and honest — do not soften problems

Format each insight as: "INSIGHT [N]: [title] — [explanation and specific action]"
EOT,
            ],
        ];

        foreach ($prompts as $data) {
            Prompt::updateOrCreate(['key' => $data['key']], $data);
        }
    }
}
