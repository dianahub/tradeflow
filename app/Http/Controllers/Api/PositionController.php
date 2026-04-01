<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AnthropicApiException;
use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\AI\TradingAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    public function __construct(private readonly TradingAnalysisService $analysis) {}

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
                $result = $this->upsertPosition($item);
                if ($result !== null) {
                    $created[] = $result;
                }
            }
            return response()->json([
                'message'   => count($created) . ' positions imported.',
                'positions' => $created,
            ], 201);
        }

        // Single position
        $position = $this->upsertPosition($data);
        if ($position === null) {
            return response()->json(['message' => 'Symbol skipped (not a valid ticker).'], 422);
        }
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
 */
public function sellRecommendations(): JsonResponse
{
    $positions = auth()->user()->positions()->orderBy('value', 'desc')->get();

    if ($positions->isEmpty()) {
        return response()->json(['error' => 'No positions found.'], 422);
    }

    try {
        $result = $this->analysis->getSellRecommendations(auth()->user(), $positions);
    } catch (AnthropicApiException $e) {
        return response()->json(['message' => $e->getMessage()], 503);
    }

    return response()->json($result);
}
    /**
     * POST /api/positions/{position}/analyze
     */
    public function analyzeOne(Position $position): JsonResponse
    {
        try {
            $result = $this->analysis->analyzePosition(auth()->user(), $position);
        } catch (AnthropicApiException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(array_merge(['position' => $position], $result));
    }

    /**
     * POST /api/positions/analyze
     */
    public function analyze(): JsonResponse
    {
        $positions = auth()->user()->positions()->orderBy('value', 'desc')->get();

        if ($positions->isEmpty()) {
            return response()->json(['error' => 'No positions found. Import your portfolio first.'], 422);
        }

        try {
            $result = $this->analysis->analyzePortfolio(auth()->user(), $positions);
        } catch (AnthropicApiException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(array_merge([
            'summary' => [
                'total_value' => round($positions->sum('value'), 2),
                'total_gain'  => round($positions->sum('total_gain_dollar'), 2),
                'today_gain'  => round($positions->sum('days_gain_dollar'), 2),
                'winners'     => $positions->where('total_gain_dollar', '>', 0)->count(),
                'losers'      => $positions->where('total_gain_dollar', '<', 0)->count(),
            ],
        ], $result));
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    // Brokerage account activity labels that are not real ticker symbols
    private const SKIP_SYMBOLS = [
        'TRANSFER', 'CASH', 'DIVIDEND', 'INTEREST', 'FEE', 'MARGIN',
        'WIRE', 'ACH', 'JOURNAL', 'ADJUSTMENT', 'PENDING', 'SWEEP',
        'MONEY MARKET', 'CORE', 'REINVESTMENT',
    ];

    private function upsertPosition(array $data): ?Position
    {
        $symbol = strtoupper(trim($data['symbol'] ?? ''));

        // Skip non-ticker entries from brokerage statements
        $assetType = $data['asset_type'] ?? 'stock';
        if (in_array($symbol, self::SKIP_SYMBOLS, true) || (str_contains($symbol, ' ') && $assetType !== 'option')) {
            return null;
        }

        foreach (['change_percent', 'total_gain_percent'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = str_replace('%', '', $data[$field]);
            }
        }

        $uniqueKey = [
            'user_id'    => auth()->id(),
            'symbol'     => strtoupper($data['symbol']),
            'asset_type' => $data['asset_type'] ?? 'stock',
        ];

        // Options need strike+expiration+type to distinguish contracts on the same underlying
        if (($data['asset_type'] ?? 'stock') === 'option') {
            $uniqueKey['option_type']      = $data['option_type']      ?? null;
            $uniqueKey['strike_price']     = $data['strike_price']     ?? null;
            $uniqueKey['expiration_date']  = $data['expiration_date']  ?? null;
        }

        return Position::updateOrCreate(
            $uniqueKey,
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