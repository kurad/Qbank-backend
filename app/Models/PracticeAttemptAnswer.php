<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticeAttemptAnswer extends Model
{
    protected $fillable = [
        'practice_attempt_id',
        'question_id',
        'answer',
        'is_correct',
        'review_only',
        'marks_awarded',
        'answered_at',
    ];

    protected $casts = [
        'answer' => 'array',
        'is_correct' => 'boolean',
        'review_only' => 'boolean',
        'marks_awarded' => 'decimal:2',
        'answered_at' => 'datetime',
    ];

    public function attempt()
    {
        return $this->belongsTo(
            PracticeAttempt::class,
            'practice_attempt_id'
        );
    }

    public function question()
    {
        return $this
            ->belongsTo(Question::class)
            ->withTrashed();
    }
}
