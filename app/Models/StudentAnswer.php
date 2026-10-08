<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_assessment_id',
        'question_id',
        'answer',
        'is_correct',
        'points_earned',
        'reviewed_by',
        'reviewed_at',
        'teacher_feedback',
        'confidence_score',
        'submitted_at',
    ];
    protected $casts = [
        'answer' => 'array',      // ✅ this is the key
        'is_correct' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'points_earned' => 'decimal:2',
        
    ];

    public function studentAssessment()
    {
        return $this->belongsTo(StudentAssessment::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
    public function assignedBy() {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    
}
