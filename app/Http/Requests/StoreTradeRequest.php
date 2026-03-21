<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth is handled by sanctum middleware
    }

    public function rules(): array
    {
        return [
            'symbol'      => 'required|string|max:10|uppercase',
            'asset_type'  => 'required|in:crypto,stock',
            'direction'   => 'required|in:long,short',
            'entry_price' => 'required|numeric|min:0.000001',
            'quantity'    => 'required|numeric|min:0.000001',
            'fees'        => 'nullable|numeric|min:0',
            'opened_at'   => 'required|date',
            'notes'       => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'symbol.required'      => 'A trading symbol is required (e.g. BTC, AAPL)',
            'asset_type.in'        => 'Asset type must be crypto or stock',
            'direction.in'         => 'Direction must be long or short',
            'entry_price.min'      => 'Entry price must be greater than zero',
            'quantity.min'         => 'Quantity must be greater than zero',
        ];
    }
}