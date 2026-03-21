<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exit_price' => 'nullable|numeric|min:0.000001',
            'fees'       => 'nullable|numeric|min:0',
            'notes'      => 'nullable|string|max:1000',
            'closed_at'  => 'nullable|date',
        ];
    }
}