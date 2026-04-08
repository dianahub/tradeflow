<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prompt extends Model
{
    protected $fillable = ['key', 'label', 'description', 'template', 'version'];

    public function versions()
    {
        return $this->hasMany(PromptVersion::class, 'prompt_key', 'key')
                    ->orderByDesc('version');
    }
}
