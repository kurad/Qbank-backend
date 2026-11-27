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
        'learning_period_id',
        'is_system_generated',
        'is_mandatory',
        'purpose',
        'question_count',
        'delivery_mode',
        'due_date',
        'shuffle_questions',
        'shuffle_options',
        'is_timed',
        'time_limit',
        'instructions',
        'question_blueprint',
        'status',
    ];

    protected $casts = [
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'is_timed' => 'boolean',
        'is_system_generated' => 'boolean',
        'is_mandatory' => 'boolean',
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
        )->withPivot('audience', 'locked_at');
    }

    /**
     * The moment after which a submission counts as late, or null when this
     * assessment has no deadline to enforce. Only teacher-set online
     * assignments are tracked (offline assessments default their due date to
     * "now", and practice / system formative work has no deadline).
     * A date without a time of day means "end of that day".
     */
    public function lateAfter(): ?\Illuminate\Support\Carbon
    {
        if (
            !$this->due_date
            || $this->delivery_mode !== 'online'
            || !in_array($this->type, ['quiz', 'homework', 'exam'], true)
            || $this->is_system_generated
            || $this->purpose !== null
        ) {
            return null;
        }

        $due = \Illuminate\Support\Carbon::parse($this->due_date);

        return $due->format('H:i:s') === '00:00:00' ? $due->endOfDay() : $due;
    }

    public function isLateAt(\DateTimeInterface $when): bool
    {
        $after = $this->lateAfter();

        return $after !== null && \Illuminate\Support\Carbon::instance($when)->greaterThan($after);
    }

    /**
     * May this student open and submit the assessment?
     * Yes if it was given to them individually (they hold a record), or it
     * was assigned to the whole of a class they belong to.
     */
    public function isAvailableToStudent(int $studentId): bool
    {
        if ($this->studentAssessments()->where('student_id', $studentId)->exists()) {
            return true;
        }

        return $this->groups()
            ->wherePivot('audience', 'all')
            ->whereHas('students', fn ($q) => $q->where('users.id', $studentId))
            ->exists();
    }
    /**
     * Has the teacher closed this assessment to the student?
     *
     * Closed means: every class that gives the student access to it is
     * locked, they have not been given an individual exception, and they have
     * not already started. A student who has started (or finished) is never
     * locked out by this: finishing an attempt in progress stays possible, and
     * submitted attempts are handled by the "already submitted" rules.
     */
    public function isLockedForStudent(int $studentId): bool
    {
        $attempt = $this->studentAssessments()->where('student_id', $studentId)->first();

        if ($attempt && (
            $attempt->access_override
            || in_array($attempt->status, ['in_progress', 'completed', 'graded', 'under_review'], true)
        )) {
            return false;
        }

        $granting = $this->groups()
            ->whereHas('students', fn ($q) => $q->where('users.id', $studentId))
            ->get()
            ->filter(fn ($group) => $group->pivot->audience === 'all' || $attempt !== null);

        if ($granting->isEmpty()) {
            return false;
        }

        return $granting->every(fn ($group) => $group->pivot->locked_at !== null);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'group_students', 'group_id', 'student_id');
    }


    public function learningPeriod()
    {
        return $this->belongsTo(LearningPeriod::class);
    }

    public function gradeSubject()
    {
        return $this->belongsTo(GradeSubject::class, 'grade_subject_id');
    }
}
