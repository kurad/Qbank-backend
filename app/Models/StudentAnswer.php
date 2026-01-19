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
        'confidence_score',
        'submitted_at',
    ];
    protected $casts = [
        'answer' => 'array',      // ✅ this is the key
        'is_correct' => 'boolean',
        'submitted_at' => 'datetime',
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
    
}
