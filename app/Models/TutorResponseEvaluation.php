<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TutorResponseEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tutor_session_id',
        'tutor_session_objective_id',
        'student_id',
        'student_response',
        'classification',
        'correct',
        'understanding_score',
        'feedback',
        'evidence',
        'misconception',
    ];

    protected $casts = [
        'correct' => 'boolean',
        'understanding_score' => 'integer',
    ];

    public function session()
    {
        return $this->belongsTo(
            TutorSession::class,
            'tutor_session_id'
        );
    }

    public function sessionObjective()
    {
        return $this->belongsTo(
            TutorSessionObjective::class,
            'tutor_session_objective_id'
        );
    }

    public function student()
    {
        return $this->belongsTo(
            User::class,
            'student_id'
        );
    }
}