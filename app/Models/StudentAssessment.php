<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'assessment_id', 'assigned_by',
        'assigned_at', 'score', 'max_score',
        'status', 'completed_at'
        
    ];
    public $timestamps = false;

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }

    public function studentAnswers()
    {
        return $this->hasMany(StudentAnswer::class);
    }
    public function group()
    {
        return $this->belongsTo(Group::class);
    }
}
