<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AnthropicApiException;
use App\Http\Controllers\Controller;
use App\Services\AI\TradingAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly TradingAnalysisService $analysis) {}

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
        $trades = $request->user()->trades()->closed()->latest('closed_at')->take(30)->get();

        if ($trades->count() < 3) {
            return response()->json([
                'message' => 'Log at least 3 closed trades to generate insights.',
            ], 422);
        }

        try {
            $result = $this->analysis->getJournalInsights($request->user(), $trades);
        } catch (AnthropicApiException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(array_merge(['trades_analyzed' => $trades->count()], $result));
    }
}