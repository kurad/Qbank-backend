<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIUsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'school_id',
        'provider',
        'model',
        'operation',
        'feature',
        'route',
        'status',
        'is_fallback',
        'http_status',
        'finish_reason',
        'prompt_tokens',
        'output_tokens',
        'thinking_tokens',
        'cached_tokens',
        'total_tokens',
        'latency_ms',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'is_fallback' => 'boolean',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
