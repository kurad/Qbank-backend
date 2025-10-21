<?php

namespace App\Models;

use App\Models\Quiz;
use App\Models\Subject;
use App\Models\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = ['topic_name', 'grade_subject_id'];

    public function gradeSubject()
    {
        return $this->belongsTo(GradeSubject::class);
    }
    public function questions()
    {
        return $this->hasMany(Question::class);
    }
    public function gradeLevels()
    {
        return $this->belongsToMany(GradeLevel::class, 'grade_subjects', 'subject_id','grade_level_id'); // Assuming a many-to-many relationship
    }
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
    public function assessments()
    {
        return $this->belongsToMany(Assessment::class, 'assessment_topic', 'topic_id', 'assessment_id')->withTimestamps();
    }

}

