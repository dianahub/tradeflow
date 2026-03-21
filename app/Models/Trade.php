<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Trade extends Model
{
    protected $fillable = [
        'user_id', 'symbol', 'asset_type', 'direction',
        'entry_price', 'exit_price', 'quantity', 'fees',
        'opened_at', 'closed_at', 'status', 'notes',
    ];
protected $attributes = [
    'status' => 'open',
    'fees'   => 0,
];
    protected $casts = [
        'entry_price' => 'decimal:6',
        'exit_price'  => 'decimal:6',
        'quantity'    => 'decimal:6',
        'fees'        => 'decimal:4',
        'opened_at'   => 'datetime',
        'closed_at'   => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Computed Attributes ──────────────────────────────
    public function getPnlAttribute(): ?float
    {
        if (!$this->exit_price) return null;

        $raw = $this->direction === 'long'
            ? ($this->exit_price - $this->entry_price) * $this->quantity
            : ($this->entry_price - $this->exit_price) * $this->quantity;

        return round($raw - $this->fees, 2);
    }

    public function getPnlPercentAttribute(): ?float
    {
        if (!$this->exit_price) return null;

        $cost = $this->entry_price * $this->quantity;
        return $cost > 0 ? round(($this->pnl / $cost) * 100, 4) : null;
    }

    // ─── Query Scopes ─────────────────────────────────────
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    public function scopeForSymbol(Builder $query, string $symbol): Builder
    {
        return $query->where('symbol', strtoupper($symbol));
    }

    public function scopeWinners(Builder $query): Builder
    {
        return $query->closed()->whereRaw(
            '((direction = "long" AND exit_price > entry_price)
            OR (direction = "short" AND exit_price < entry_price))'
        );
    }
}