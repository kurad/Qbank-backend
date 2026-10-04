<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningPeriodTopic extends Model
{
    use HasFactory;

    protected $fillable = [
        'learning_period_id',
        'unit_id',
        'topic_id',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function learningPeriod(): BelongsTo
    {
        return $this->belongsTo(
            LearningPeriod::class,
            'learning_period_id'
        );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}