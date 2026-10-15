<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearnerFormativeAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'learning_period_id',
        'assessment_id',
        'student_assessment_id',
        'status',
        'progress',
        'stage',
        'message',
        'seed',
        'question_count',
        'error_message',
        'prepared_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'progress' => 'integer',
        'question_count' => 'integer',
        'prepared_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function learningPeriod()
    {
        return $this->belongsTo(LearningPeriod::class);
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function studentAssessment()
    {
        return $this->belongsTo(StudentAssessment::class);
    }

    public function questions()
    {
        return $this->hasMany(LearnerFormativeQuestion::class)
            ->orderBy('display_order');
    }
}
