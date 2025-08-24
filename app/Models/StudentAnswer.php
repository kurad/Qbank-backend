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
        'submitted_at',
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
