<?php

namespace App\Models;

use App\Models\TutorResponseEvaluation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TutorSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'group_id',
        'learning_period_id',
        'grade_subject_id',
        'subject_id',
        'unit_id',
        'topic_id',
        'learning_objective_id',
        'course_material_id',
        'title',
        'status',
        'started_at',
        'last_activity_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function learningPeriod(): BelongsTo
    {
        return $this->belongsTo(
            LearningPeriod::class,
            'learning_period_id'
        );
    }

    public function gradeSubject(): BelongsTo
    {
        return $this->belongsTo(GradeSubject::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function learningObjective(): BelongsTo
    {
        return $this->belongsTo(
            LearningObjective::class,
            'learning_objective_id'
        );
    }

    public function courseMaterial(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(
            TutorSessionObjective::class,
            'tutor_session_id'
        )->orderBy('objective_order');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(
            TutorMessage::class,
            'tutor_session_id'
        );
    }
    public function responseEvaluations()
{
    return $this->hasMany(
        TutorResponseEvaluation::class,
        'tutor_session_id'
    );
}
}