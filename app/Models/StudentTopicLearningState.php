<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTopicLearningState extends Model
{
    protected $fillable = [
        'student_id',
        'learning_period_id',
        'topic_id',
        'progression_status',
        'mastery_status',
        'attempt_count',
        'best_score',
        'last_score',
        'move_on_chosen',
        'move_on_reason',
        'move_on_at',
        'mastered_at',
        'last_attempt_at',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'best_score' => 'integer',
        'last_score' => 'integer',
        'move_on_chosen' => 'boolean',
        'move_on_at' => 'datetime',
        'mastered_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function learningPeriod(): BelongsTo
    {
        return $this->belongsTo(LearningPeriod::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
