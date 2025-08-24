<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'creator_id',
        'topic_id',
        'question_count',
        'delivery_mode',
        'due_date',
        'is_timed',
        'time_limit',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function assessmentQuestions()
    {
        return $this->hasMany(AssessmentQuestion::class);
    }

    public function studentAssessments()
    {
        return $this->hasMany(StudentAssessment::class);
    }
    public function topic()
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }
    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class);
    }
}
