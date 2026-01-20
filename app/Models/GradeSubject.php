<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradeSubject extends Model
{
    use HasFactory;
    protected $fillable = [
        'grade_level_id',
        'subject_id',
    ];

    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class, 'grade_level_id');
    }
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
    public function topics()
    {
        return $this->hasMany(Topic::class);
    }
}
