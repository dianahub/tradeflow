<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'symbol'      => $this->symbol,
            'asset_type'  => $this->asset_type,
            'direction'   => $this->direction,
            'entry_price' => (float) $this->entry_price,
            'exit_price'  => $this->exit_price ? (float) $this->exit_price : null,
            'quantity'    => (float) $this->quantity,
            'fees'        => (float) $this->fees,
            'pnl'         => $this->pnl,
            'pnl_percent' => $this->pnl_percent,
            'status'      => $this->status,
            'opened_at'   => $this->opened_at->toIso8601String(),
            'closed_at'   => $this->closed_at?->toIso8601String(),
            'notes'       => $this->notes,
            'created_at'  => $this->created_at->toIso8601String(),
        ];
    }
}