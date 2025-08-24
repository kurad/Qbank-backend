<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradeLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_name',
    ];

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'grade_subjects');
    }
    public function gradeSubjects()
    {
        return $this->hasMany(GradeSubject::class);
    }
    public function topics()
    {
        return $this->hasManyThrough(Topic::class, GradeSubject::class);
    }
}
