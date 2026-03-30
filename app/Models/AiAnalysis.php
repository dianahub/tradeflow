<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'analysis_type',
        'subject_key',
        'context_hash',
        'prompt_summary',
        'analysis_text',
        'embedding',
        'embedding_model',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Return the embedding as a float array, or null if not yet generated. */
    public function getEmbeddingArray(): ?array
    {
        if (!$this->embedding) {
            return null;
        }
        return json_decode($this->embedding, true);
    }
}
