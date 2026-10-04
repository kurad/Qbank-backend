<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_subject_id',
        'unit_id',
        'curriculum_analysis_id',
        'topic_name',
        'order',
        'status',
        'created_by',
    ];

    public function gradeSubject(): BelongsTo
    {
        return $this->belongsTo(
            GradeSubject::class,
            'grade_subject_id'
        );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(
            Unit::class,
            'unit_id'
        );
    }

    public function curriculumAnalysis(): BelongsTo
    {
        return $this->belongsTo(
            CurriculumAnalysis::class,
            'curriculum_analysis_id'
        );
    }

    public function learningObjectives(): HasMany
    {
        return $this->hasMany(
            LearningObjective::class
        )->orderBy('order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function questions()
    {
        return $this->hasMany(
            Question::class
        );
    }

    public function assessments()
    {
        return $this->belongsToMany(
            Assessment::class,
            'assessment_topic'
        );
    }

    
}