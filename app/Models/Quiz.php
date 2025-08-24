<?php

namespace App\Models;

use App\Models\User;
use App\Models\Topic;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Quiz extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'teacher_id',
        'subject_id',
        'topic_id',
        'target_grade', // e.g., 'S4'
        'question_count',
        'due_date',
    ];



    public function teacher()
{
    return $this->belongsTo(User::class, 'teacher_id');
}

public function subject()
{
    return $this->belongsTo(Subject::class);
}

public function topic()
{
    return $this->belongsTo(Topic::class);
}

// Many-to-Many with questions
public function questions()
{
    return $this->belongsToMany(Question::class, 'quiz_question');
}

// One-to-Many with student submissions
public function submissions()
{
    return $this->hasMany(QuizSubmission::class);
}

}
