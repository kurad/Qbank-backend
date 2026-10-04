<?php

namespace App\Models;

use App\Models\Topic;
use App\Models\GradeSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subject extends Model
{
    use HasFactory;
    protected $fillable = ['name', 'school_id', 'created_by'];



    public function gradeSubjects()
    {
        return $this->hasMany(GradeSubject::class);
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }
    public function assessments()
    {
        return $this->hasMany(Assessment::class);
    }
    public function gradeLevels()
    {
        return $this->belongsToMany(GradeLevel::class, 'grade_subjects');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
