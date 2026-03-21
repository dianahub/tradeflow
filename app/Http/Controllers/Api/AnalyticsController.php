<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $trades  = $request->user()->trades()->closed()->get();
        $winners = $trades->filter(fn($t) => $t->pnl > 0);
        $losers  = $trades->filter(fn($t) => $t->pnl < 0);

        $totalPnl     = $trades->sum('pnl');
        $winRate      = $trades->count() > 0
            ? round(($winners->count() / $trades->count()) * 100, 2)
            : 0;
        $avgWin       = $winners->count() > 0 ? $winners->avg('pnl') : 0;
        $avgLoss      = $losers->count()  > 0 ? abs($losers->avg('pnl')) : 0;
        $profitFactor = $avgLoss > 0 ? round($avgWin / $avgLoss, 2) : null;

        return response()->json([
            'total_trades'  => $trades->count(),
            'open_trades'   => $request->user()->trades()->open()->count(),
            'total_pnl'     => round($totalPnl, 2),
            'win_rate'      => $winRate,
            'avg_win'       => round($avgWin, 2),
            'avg_loss'      => round($avgLoss, 2),
            'profit_factor' => $profitFactor,
            'best_symbol'   => $winners->sortByDesc('pnl')->first()?->symbol,
            'worst_symbol'  => $losers->sortBy('pnl')->first()?->symbol,
        ]);
    }

    public function winRate(Request $request): JsonResponse
    {
        $trades = $request->user()->trades()->closed()->get();

        $bySymbol = $trades->groupBy('symbol')->map(function ($group, $symbol) {
            $winners = $group->filter(fn($t) => $t->pnl > 0);
            return [
                'symbol'      => $symbol,
                'trades'      => $group->count(),
                'wins'        => $winners->count(),
                'losses'      => $group->count() - $winners->count(),
                'win_rate'    => round(($winners->count() / $group->count()) * 100, 2),
                'total_pnl'   => round($group->sum('pnl'), 2),
            ];
        })->values();

        return response()->json($bySymbol);
    }

    public function pnlBySymbol(Request $request): JsonResponse
    {
        $trades = $request->user()->trades()->closed()->get();

        $data = $trades->groupBy('symbol')->map(function ($group, $symbol) {
            return [
                'symbol'      => $symbol,
                'trade_count' => $group->count(),
                'total_pnl'   => round($group->sum('pnl'), 2),
                'avg_pnl'     => round($group->avg('pnl'), 2),
                'best_trade'  => round($group->max('pnl'), 2),
                'worst_trade' => round($group->min('pnl'), 2),
                'win_rate'    => round(
                    ($group->filter(fn($t) => $t->pnl > 0)->count() / $group->count()) * 100, 2
                ),
            ];
        })->values();

        return response()->json($data);
    }

   public function aiInsights(Request $request): JsonResponse
{
    $user   = $request->user();
    $trades = $user->trades()->closed()->latest('closed_at')->take(30)->get();

    if ($trades->count() < 3) {
        return response()->json([
            'message' => 'Log at least 3 closed trades to generate insights.',
        ], 422);
    }

    $winners      = $trades->filter(fn($t) => $t->pnl > 0);
    $losers       = $trades->filter(fn($t) => $t->pnl < 0);
    $winRate      = round(($winners->count() / $trades->count()) * 100, 2);
    $avgWin       = $winners->count() > 0 ? round($winners->avg('pnl'), 2) : 0;
    $avgLoss      = $losers->count()  > 0 ? round(abs($losers->avg('pnl')), 2) : 0;
    $profitFactor = $avgLoss > 0 ? round($avgWin / $avgLoss, 2) : null;

    $tradeList = $trades->map(fn($t) => [
        'symbol'    => $t->symbol,
        'direction' => $t->direction,
        'pnl'       => $t->pnl,
        'pnl_pct'   => $t->pnl_percent,
        'date'      => $t->opened_at->format('Y-m-d'),
    ])->toArray();

    $prompt = "You are a professional trading coach analyzing a trader's journal.

Here are their last {$trades->count()} closed trades:
" . json_encode($tradeList, JSON_PRETTY_PRINT) . "

Overall stats:
- Win rate: {$winRate}%
- Average win: \${$avgWin}
- Average loss: \${$avgLoss}
- Profit factor: {$profitFactor}

Give 3 specific, actionable insights based on this data. Reference actual symbols and numbers.
Be direct and honest. Format as 3 numbered points.";

    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'x-api-key'         => config('services.anthropic.key'),
        'anthropic-version' => '2023-06-01',
        'content-type'      => 'application/json',
    ])->post('https://api.anthropic.com/v1/messages', [
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 600,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ]);

    if ($response->failed()) {
        return response()->json([
            'message' => 'AI service unavailable',
            'error'   => $response->json('error.message'),
        ], 503);
    }

    return response()->json([
        'insights'        => $response->json('content.0.text'),
        'trades_analyzed' => $trades->count(),
        'generated_at'    => now()->toIso8601String(),
    ]);
}
}