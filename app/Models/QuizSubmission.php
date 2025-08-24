<?php

namespace App\Models;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuizSubmission extends Model
{
    use HasFactory;
    protected $fillable = [
        'student_id',
        'quiz_id',
        'answers', // JSON field to store student's answers
        'score', // Numeric field to store the score
        'submitted_at', // Timestamp for when the quiz was submitted
    ];



    public function student()
{
    return $this->belongsTo(User::class, 'student_id');
}

public function quiz()
{
    return $this->belongsTo(Quiz::class);
}

}
