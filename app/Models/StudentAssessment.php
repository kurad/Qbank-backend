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
    protected $casts = [
    'assigned_at'   => 'datetime',
    'completed_at'  => 'datetime',
    'score'         => 'float',
    'max_score'     => 'float',
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
