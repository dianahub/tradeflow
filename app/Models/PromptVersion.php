<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['prompt_key', 'template', 'version', 'saved_by', 'change_note', 'saved_at'];

    protected $casts = ['saved_at' => 'datetime'];

    public function savedBy()
    {
        return $this->belongsTo(User::class, 'saved_by');
    }
}
