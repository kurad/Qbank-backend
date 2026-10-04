<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_name',
        'class_code',
        'created_by',
        'grade_subject_id',
        'academic_year',
    ];


    public function gradeSubject()
    {
        return $this->belongsTo(GradeSubject::class, 'grade_subject_id');
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'group_students', 'group_id', 'student_id');
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function assessments()
    {
        return $this->belongsToMany(
            Assessment::class,
            'assessment_groups',
            'group_id',
            'assessment_id'
        );
    }
    public function learningPeriods()
    {
        return $this->hasMany(LearningPeriod::class);
    }
    public function tutorSessions()
    {
        return $this->hasMany(TutorSession::class);
    }
}
