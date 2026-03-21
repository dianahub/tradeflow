<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTradeRequest;
use App\Http\Requests\UpdateTradeRequest;
use App\Http\Resources\TradeResource;
use App\Models\Trade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TradeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $trades = $request->user()
            ->trades()
            ->latest('opened_at')
            ->get();

        return TradeResource::collection($trades);
    }

    public function store(StoreTradeRequest $request): TradeResource
    {
        $trade = $request->user()->trades()->create($request->validated());

        return new TradeResource($trade);
    }

    public function show(Request $request, Trade $trade): TradeResource|JsonResponse
    {
        if ($trade->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new TradeResource($trade);
    }

    public function update(UpdateTradeRequest $request, Trade $trade): TradeResource|JsonResponse
    {
        if ($trade->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $trade->update($request->validated());

        return new TradeResource($trade->fresh());
    }

    public function destroy(Request $request, Trade $trade): JsonResponse
    {
        if ($trade->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $trade->delete();

        return response()->json(['message' => 'Trade deleted']);
    }

    public function close(Request $request, Trade $trade): JsonResponse
    {
        if ($trade->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'exit_price' => 'required|numeric|min:0.000001',
            'closed_at'  => 'nullable|date',
        ]);

        $trade->update([
            'exit_price' => $request->exit_price,
            'closed_at'  => $request->closed_at ?? now(),
            'status'     => 'closed',
        ]);

        $trade = $trade->fresh();

        return response()->json([
            'trade'       => new TradeResource($trade),
            'pnl'         => $trade->pnl,
            'pnl_percent' => $trade->pnl_percent,
        ]);
    }
}