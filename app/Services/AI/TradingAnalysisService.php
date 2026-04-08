<?php

namespace App\Services\AI;

use App\Exceptions\AnthropicApiException;
use App\Models\AiAnalysis;
use App\Models\Position;
use App\Models\Prompt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TradingAnalysisService
{
    public function __construct(
        private readonly AnthropicService $anthropic,
        private readonly EmbeddingService $embeddings,
        private readonly AnalysisCacheService $cache,
    ) {}

    // ── Public analysis methods ────────────────────────────────────────────────

    /**
     * Analyse the full portfolio.
     *
     * @throws AnthropicApiException
     */
    public function analyzePortfolio(User $user, Collection $positions): array
    {
        $contextData  = $this->portfolioContextData($positions);
        $contextHash  = $this->cache->hashContext($contextData);
        $promptSummary = $this->buildPortfolioPromptSummary($positions);

        if ($cached = $this->cache->find($user->id, 'portfolio', $contextHash)) {
            return $this->response($cached->analysis_text, fromCache: true);
        }

        $ragContext = $this->retrieveRagContext($user->id, 'portfolio', $promptSummary);
        $text       = $this->anthropic->complete($this->buildPortfolioPrompt($positions, $ragContext), maxTokens: 1200);

        $this->cache->store($user->id, 'portfolio', 'portfolio', $contextHash, $promptSummary, $text, $contextData);

        return $this->response($text);
    }

    /**
     * Analyse a single position.
     *
     * @throws AnthropicApiException
     */
    public function analyzePosition(User $user, Position $position): array
    {
        $contextData   = $this->positionContextData($position);
        $contextHash   = $this->cache->hashContext($contextData);
        $promptSummary = $this->buildPositionPromptSummary($position);

        if ($cached = $this->cache->find($user->id, 'position', $contextHash)) {
            return $this->response($cached->analysis_text, fromCache: true);
        }

        $ragContext = $this->retrieveRagContext($user->id, 'position', $promptSummary, $position->symbol);
        $text       = $this->anthropic->complete($this->buildPositionPrompt($position, $ragContext), maxTokens: 1000);

        $this->cache->store($user->id, 'position', $position->symbol, $contextHash, $promptSummary, $text, $contextData);

        return $this->response($text);
    }

    /**
     * Generate sell recommendations for the portfolio.
     *
     * @throws AnthropicApiException
     */
    public function getSellRecommendations(User $user, Collection $positions): array
    {
        $contextData  = $this->portfolioContextData($positions);
        $contextHash  = $this->cache->hashContext(['sell_v2', ...$contextData]);
        $promptSummary = $this->buildPortfolioPromptSummary($positions);

        if ($cached = $this->cache->find($user->id, 'sell_recommendations', $contextHash)) {
            return $this->response($cached->analysis_text, fromCache: true);
        }

        $ragContext = $this->retrieveRagContext($user->id, 'sell_recommendations', $promptSummary);
        $text       = $this->anthropic->complete($this->buildSellRecommendationsPrompt($positions, $ragContext), maxTokens: 300);

        $this->cache->store($user->id, 'sell_recommendations', 'portfolio', $contextHash, $promptSummary, $text, $contextData);

        return $this->response($text);
    }

    /**
     * Generate trade journal insights from recent closed trades.
     *
     * @throws AnthropicApiException
     */
    public function getJournalInsights(User $user, Collection $trades): array
    {
        $contextData  = $this->journalContextData($trades);
        $contextHash  = $this->cache->hashContext($contextData);
        $promptSummary = $this->buildJournalPromptSummary($trades, $contextData);

        if ($cached = $this->cache->find($user->id, 'journal_insights', $contextHash)) {
            return $this->response($cached->analysis_text, fromCache: true);
        }

        $ragContext = $this->retrieveRagContext($user->id, 'journal_insights', $promptSummary);
        $text       = $this->anthropic->complete($this->buildJournalInsightsPrompt($trades, $ragContext), maxTokens: 800);

        $this->cache->store($user->id, 'journal_insights', 'journal', $contextHash, $promptSummary, $text, $contextData);

        return $this->response($text);
    }

    // ── RAG context retrieval ──────────────────────────────────────────────────

    private function retrieveRagContext(int $userId, string $type, string $promptSummary, ?string $symbol = null): string
    {
        // For position analyses, also bias retrieval toward the same symbol
        if ($symbol) {
            $symbolMatches = AiAnalysis::where('user_id', $userId)
                ->where('analysis_type', $type)
                ->where('subject_key', $symbol)
                ->whereNotNull('embedding')
                ->latest()
                ->limit(3)
                ->get();
        } else {
            $symbolMatches = collect();
        }

        $similarMatches = $this->embeddings->findSimilar($userId, $promptSummary, $type, topK: 3);

        // Merge, deduplicate, keep most recent 3
        $combined = $symbolMatches->merge($similarMatches)
            ->unique('id')
            ->take(3);

        if ($combined->isEmpty()) {
            return '';
        }

        $lines = $combined->map(fn(AiAnalysis $a) => sprintf(
            '[%s] %s: %s',
            $a->created_at->format('Y-m-d'),
            ucfirst(str_replace('_', ' ', $a->analysis_type)) . ($a->subject_key !== 'portfolio' && $a->subject_key !== 'journal' ? " ({$a->subject_key})" : ''),
            mb_substr($a->analysis_text, 0, 200) . (mb_strlen($a->analysis_text) > 200 ? '...' : '')
        ));

        return "RELEVANT PAST ANALYSES (historical context only — do not use these as current data):\n---\n"
            . $lines->implode("\n")
            . "\n---\n\n";
    }

    // ── Prompt builders ────────────────────────────────────────────────────────

    private function buildPortfolioPrompt(Collection $positions, string $ragContext): string
    {
        $today        = now()->startOfDay();
        $totalValue   = $positions->sum('value');
        $totalGain    = $positions->sum('total_gain_dollar');
        $totalGainPct = $totalValue > 0 ? round(($totalGain / ($totalValue - $totalGain)) * 100, 2) : 0;
        $dayGain      = $positions->sum('days_gain_dollar');

        $positionList = $positions->map(function (Position $p) use ($today) {
            $line = "- {$p->symbol} ({$p->asset_type}): \${$p->value} value, "
                . "\${$p->total_gain_dollar} total gain ({$p->total_gain_percent}%), "
                . "\${$p->days_gain_dollar} today";
            if ($p->asset_type === 'option' && $p->expiration_date) {
                $dte  = (int) $today->diffInDays(Carbon::parse($p->expiration_date), false);
                $line .= ", {$p->option_type} strike \${$p->strike_price} exp {$p->expiration_date} ({$dte} days to expiry)";
            }
            return $line;
        })->implode("\n");

        $template = $this->getTemplate('portfolio_analysis');

        $filled = str_replace([
            '{{TODAY}}', '{{TOTAL_VALUE}}', '{{TOTAL_GAIN}}', '{{TOTAL_GAIN_PCT}}',
            '{{DAY_GAIN}}', '{{POSITION_COUNT}}', '{{POSITION_LIST}}',
        ], [
            $today->toDateString(), $totalValue, $totalGain, $totalGainPct,
            $dayGain, $positions->count(), $positionList,
        ], $template);

        return $ragContext . $filled;
    }

    private function buildPositionPrompt(Position $position, string $ragContext): string
    {
        $today = now()->startOfDay();
        $optionInfo = '';
        if ($position->asset_type === 'option') {
            $dte = $position->expiration_date
                ? (int) $today->diffInDays(Carbon::parse($position->expiration_date), false)
                : null;
            $optionInfo = "\nOption Type: {$position->option_type} | Strike: \${$position->strike_price} | Expiry: {$position->expiration_date}"
                . ($dte !== null ? " ({$dte} days to expiry)" : '')
                . " | Underlying: {$position->underlying_symbol}";
            if ($position->delta) $optionInfo .= " | Delta: {$position->delta}";
            if ($position->implied_volatility) $optionInfo .= " | IV: {$position->implied_volatility}%";
        }

        $coveredCallSection = ($position->asset_type === 'stock' && $position->quantity >= 100)
            ? "\n5. COVERED CALL RECOMMENDATION — suggest a covered call strike and expiry if appropriate"
            : '';

        $template = $this->getTemplate('position_analysis');

        $filled = str_replace([
            '{{TODAY}}', '{{SYMBOL}}', '{{ASSET_TYPE}}', '{{QUANTITY}}',
            '{{PRICE_PAID}}', '{{LAST_PRICE}}', '{{VALUE}}',
            '{{TOTAL_GAIN}}', '{{TOTAL_GAIN_PCT}}', '{{DAY_GAIN}}',
            '{{OPTION_INFO}}', '{{COVERED_CALL_SECTION}}',
        ], [
            $today->toDateString(), $position->symbol, $position->asset_type, $position->quantity,
            $position->price_paid, $position->last_price, $position->value,
            $position->total_gain_dollar, $position->total_gain_percent, $position->days_gain_dollar,
            $optionInfo, $coveredCallSection,
        ], $template);

        return $ragContext . $filled;
    }

    private function buildSellRecommendationsPrompt(Collection $positions, string $ragContext): string
    {
        $today = now()->startOfDay();

        $positionList = $positions->map(function (Position $p) use ($today) {
            $line = "- {$p->symbol}: \${$p->value} | {$p->total_gain_percent}% total | \${$p->days_gain_dollar} today";
            if ($p->asset_type === 'option' && $p->expiration_date) {
                $dte  = (int) $today->diffInDays(Carbon::parse($p->expiration_date), false);
                $line .= " | {$p->option_type} strike \${$p->strike_price} exp {$p->expiration_date} ({$dte} DTE)";
            }
            return $line;
        })->implode("\n");

        $template = $this->getTemplate('sell_recommendations');

        $filled = str_replace(
            ['{{TODAY}}', '{{POSITION_LIST}}'],
            [$today->toDateString(), $positionList],
            $template
        );

        return $ragContext . $filled;
    }

    private function buildJournalInsightsPrompt(Collection $trades, string $ragContext): string
    {
        $closed   = $trades->whereNotNull('closed_at');
        $winners  = $closed->where('pnl', '>', 0);
        $losers   = $closed->where('pnl', '<=', 0);
        $winRate  = $closed->count() > 0 ? round(($winners->count() / $closed->count()) * 100, 1) : 0;
        $avgWin   = $winners->count() > 0 ? round($winners->avg('pnl'), 2) : 0;
        $avgLoss  = $losers->count() > 0 ? round(abs($losers->avg('pnl')), 2) : 0;
        $pFactor  = $avgLoss > 0 ? round(($winners->count() * $avgWin) / ($losers->count() * $avgLoss), 2) : 0;

        $topSymbols = $closed->groupBy('symbol')
            ->map(fn($g) => ['count' => $g->count(), 'pnl' => $g->sum('pnl')])
            ->sortByDesc('count')
            ->take(5)
            ->map(fn($v, $k) => "{$k} ({$v['count']} trades, \${$v['pnl']} PnL)")
            ->implode(', ');

        $tradeList = $closed->take(20)->map(fn($t) =>
            "- {$t->symbol} {$t->direction}: entry \${$t->entry_price} → exit \${$t->exit_price}, PnL \${$t->pnl}"
        )->implode("\n");

        $template = $this->getTemplate('journal_insights');

        $filled = str_replace([
            '{{TRADE_COUNT}}', '{{WIN_RATE}}', '{{AVG_WIN}}', '{{AVG_LOSS}}',
            '{{PROFIT_FACTOR}}', '{{TOP_SYMBOLS}}', '{{TRADE_LIST}}',
        ], [
            $closed->count(), $winRate, $avgWin, $avgLoss,
            $pFactor, $topSymbols, $tradeList,
        ], $template);

        return $ragContext . $filled;
    }

    // ── Prompt summaries (what gets embedded for RAG) ──────────────────────────

    private function buildPortfolioPromptSummary(Collection $positions): string
    {
        $totalValue = $positions->sum('value');
        $totalGain  = $positions->sum('total_gain_dollar');
        $pct        = $totalValue > 0 ? round(($totalGain / max($totalValue - $totalGain, 1)) * 100, 1) : 0;
        $top3       = $positions->sortByDesc('value')->take(3)
            ->map(fn($p) => "{$p->symbol} (\${$p->value}, {$p->total_gain_percent}%)")
            ->implode(', ');

        return "Portfolio analysis: {$positions->count()} positions, total value \${$totalValue}, "
            . "total gain \${$totalGain} ({$pct}%), top positions: {$top3}";
    }

    private function buildPositionPromptSummary(Position $position): string
    {
        return "Position analysis: {$position->symbol} {$position->asset_type}, "
            . "{$position->quantity} shares, cost \${$position->price_paid}, "
            . "current \${$position->last_price}, total gain {$position->total_gain_percent}%";
    }

    private function buildJournalPromptSummary(Collection $trades, array $contextData): string
    {
        return "Trade journal analysis: {$contextData['trade_count']} closed trades, "
            . "win rate {$contextData['win_rate']}%, profit factor {$contextData['profit_factor']}, "
            . "avg win \${$contextData['avg_win']}, avg loss \${$contextData['avg_loss']}";
    }

    // ── Context data (used for cache hash + metadata storage) ─────────────────

    private function portfolioContextData(Collection $positions): array
    {
        return [
            'count'       => $positions->count(),
            'total_value' => round($positions->sum('value'), 2),
            'total_gain'  => round($positions->sum('total_gain_dollar'), 2),
            'day_gain'    => round($positions->sum('days_gain_dollar'), 2),
            'symbols'     => $positions->pluck('symbol')->sort()->values()->toArray(),
        ];
    }

    private function positionContextData(Position $position): array
    {
        return [
            'symbol'      => $position->symbol,
            'quantity'    => $position->quantity,
            'price_paid'  => $position->price_paid,
            'last_price'  => $position->last_price,
            'total_gain'  => $position->total_gain_dollar,
        ];
    }

    private function journalContextData(Collection $trades): array
    {
        $closed  = $trades->whereNotNull('closed_at');
        $winners = $closed->where('pnl', '>', 0);
        $losers  = $closed->where('pnl', '<=', 0);
        $avgWin  = $winners->count() > 0 ? round($winners->avg('pnl'), 2) : 0;
        $avgLoss = $losers->count() > 0 ? round(abs($losers->avg('pnl')), 2) : 0;
        $pFactor = ($losers->count() > 0 && $avgLoss > 0)
            ? round(($winners->count() * $avgWin) / ($losers->count() * $avgLoss), 2)
            : 0;

        return [
            'trade_count'   => $closed->count(),
            'win_rate'      => $closed->count() > 0 ? round(($winners->count() / $closed->count()) * 100, 1) : 0,
            'avg_win'       => $avgWin,
            'avg_loss'      => $avgLoss,
            'profit_factor' => $pFactor,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Load a prompt template from the DB, falling back to an empty string if not seeded yet. */
    private function getTemplate(string $key): string
    {
        static $cache = [];
        if (!isset($cache[$key])) {
            $cache[$key] = Prompt::where('key', $key)->value('template') ?? '';
        }
        return $cache[$key];
    }

    private function response(string $text, bool $fromCache = false): array
    {
        return [
            'analysis'     => $text,
            'from_cache'   => $fromCache,
            'generated_at' => now()->toISOString(),
        ];
    }
}
