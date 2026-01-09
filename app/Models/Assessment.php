<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'creator_id',
        'question_count',
        'delivery_mode',
        'due_date',
        'is_timed',
        'time_limit',
        'instructions',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function assessmentQuestions()
    {
        return $this->hasMany(AssessmentQuestion::class);
    }

    public function studentAssessments()
    {
        return $this->hasMany(StudentAssessment::class);
    }
    /**
     * Backwards-compatible accessor that returns the first topic attached to the assessment.
     * Allows existing code to use $assessment->topic->name while the canonical relation is many-to-many.
     */
    public function getTopicAttribute()
    {
        return $this->topics()->first();
    }

    public function topics()
    {
        return $this->belongsToMany(Topic::class, 'assessment_topic', 'assessment_id', 'topic_id')->withTimestamps();
    }
    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class)
        ->orderBy('order');
    }
    public function questionUsages()
    {
        return $this->hasMany(QuestionUsage::class);
    }
    public function sections()
    {
        return $this->hasMany(AssessmentSection::class)->orderBy('ordering');
    }
    public function gradeLevel()
    {
        return $this->belongsTo(GradeLevel::class);
    }
    public function groups()
{
    return $this->belongsToMany(
        Group::class,
        'assessment_groups',
        'assessment_id',
        'group_id'
    );
}
public function students()
    {
        return $this->belongsToMany(User::class, 'group_students', 'group_id', 'student_id');
    }

}
