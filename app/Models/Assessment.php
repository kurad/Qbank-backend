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
        'question_blueprint',
        'status',
    ];

    protected $casts = [
        'is_timed' => 'boolean',
        'question_blueprint' => 'array',
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
    public function getTopicAttribute()
    {
        return $this->topics()->first();
    }

    public function topics()
    {
        return $this->belongsToMany(Topic::class, 'assessment_topic', 'assessment_id', 'topic_id')->withTimestamps();
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'assessment_unit', 'assessment_id', 'unit_id')->withTimestamps();
    }

    public function learningObjectives()
    {
        return $this->belongsToMany(
            LearningObjective::class,
            'assessment_learning_objective',
            'assessment_id',
            'learning_objective_id'
        )->withTimestamps();
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
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

    public function gradeSubject()
    {
        return $this->belongsTo(GradeSubject::class, 'grade_subject_id');
    }
}
