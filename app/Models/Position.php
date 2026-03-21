<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
    'user_id',
    'symbol',
    'asset_type',
    'last_price',
    'change_dollar',
    'change_percent',
    'quantity',
    'price_paid',
    'days_gain_dollar',
    'total_gain_dollar',
    'total_gain_percent',
    'value',
    'option_type',
    'strike_price',
    'expiration_date',
    'underlying_symbol',
    'delta',
    'implied_volatility',
];

public function user()
{
    return $this->belongsTo(User::class);
}
    protected $casts = [
        'last_price'        => 'float',
        'change_dollar'     => 'float',
        'change_percent'    => 'float',
        'quantity'          => 'float',
        'price_paid'        => 'float',
        'days_gain_dollar'  => 'float',
        'total_gain_dollar' => 'float',
        'total_gain_percent'=> 'float',
        'value'             => 'float',
    ];
}
