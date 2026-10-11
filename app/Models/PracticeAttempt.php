<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticeAttempt extends Model
{
    protected $fillable = [
        'student_id',
        'group_id',
        'learning_period_id',
        'topic_id',
        'parent_attempt_id',
        'mode',
        'status',
        'total_questions',
        'correct_count',
        'total_marks',
        'earned_marks',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_marks' => 'decimal:2',
        'earned_marks' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function learningPeriod()
    {
        return $this->belongsTo(LearningPeriod::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function parentAttempt()
    {
        return $this->belongsTo(self::class, 'parent_attempt_id');
    }

    public function answers()
    {
        return $this->hasMany(PracticeAttemptAnswer::class);
    }
}
