<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'assessment_id', 'assigned_by',
        'assigned_at', 'score', 'max_score', 'original_max_score',
        'status', 'completed_at', 'question_order', 'option_order', 'is_late'
        
    ];
    protected $casts = [
    'question_order' => 'array',
    'option_order' => 'array',
    'assigned_at'   => 'datetime',
    'completed_at'  => 'datetime',
    'score'         => 'float',
    'max_score'     => 'float',
    'original_max_score' => 'float',
    'is_late'       => 'boolean',
];

    public $timestamps = false;

    // Always store normalized status
    public function setStatusAttribute($value): void
    {
        $this->attributes['status'] = $value
            ? strtolower(trim($value))
            : 'pending';
    }
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
