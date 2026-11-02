<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAnswerMarkReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_answer_id',
        'student_assessment_id',
        'reviewed_by',
        'previous_points',
        'new_points',
        'previous_is_correct',
        'new_is_correct',
        'feedback',
    ];

    protected $casts = [
        'previous_points' => 'decimal:2',
        'new_points' => 'decimal:2',
        'previous_is_correct' => 'boolean',
        'new_is_correct' => 'boolean',
    ];

    public function answer()
    {
        return $this->belongsTo(StudentAnswer::class, 'student_answer_id');
    }

    public function studentAssessment()
    {
        return $this->belongsTo(StudentAssessment::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
