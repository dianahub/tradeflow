<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioSnapshot extends Model
{
    protected $fillable = [
        'portfolio_id', 'total_value', 'total_pnl',
        'total_pnl_percent', 'allocation', 'recorded_at',
    ];

    protected $casts = [
        'allocation'  => 'array',
        'recorded_at' => 'datetime',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}