<?php

namespace App\Models;

use App\Models\User;
use App\Models\Topic;
use App\Models\GradeSubject;
use App\Models\LearningObjective;
use App\Models\AssessmentQuestion;
use App\Models\StudentAnswer;
use App\Models\QuestionUsage;
use Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'topic_id',
        'learning_objective_id',
        'question_type',
        'question',
        'question_fingerprint',
        'options',
        'correct_answer',
        'correct_answer_image',
        'marks',
        'difficulty_level',
        'is_math',
        'is_chemistry',
        'multiple_answers',
        'is_required',
        'explanation',
        'question_image',
        'created_by',
        'parent_question_id',
        'source',
        'status',
        'is_assessment_eligible',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'array',
        'is_math' => 'boolean',
        'is_chemistry' => 'boolean',
        'multiple_answers' => 'boolean',
        'is_required' => 'boolean',
        'is_assessment_eligible' => 'boolean',
        'marks' => 'decimal:2',
    ];

    protected $appends = [
        'question_image_url',
        'correct_answer_image_url',
    ];

    /*
    |--------------------------------------------------------------------------
    | Duplicate / Fingerprint Helpers
    |--------------------------------------------------------------------------
    */

    public static function normalizeQuestionText(?string $value): string
    {
        $value = strip_tags((string) $value);
        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        // Normalize common smart punctuation before collapsing characters.
        $value = str_replace(
            ['“', '”', '‘', '’', '–', '—', '…'],
            ['"', '"', "'", "'", '-', '-', '...'],
            $value
        );

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value));

        // Treat punctuation-only differences as the same question.
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return $value;
    }

    public static function buildQuestionFingerprint(
        ?int $topicId,
        ?int $learningObjectiveId,
        ?string $questionType,
        ?string $questionText
    ): string {
        $key = implode('|', [
            (int) $topicId,
            (int) ($learningObjectiveId ?? 0),
            mb_strtolower(trim((string) $questionType), 'UTF-8'),
            self::normalizeQuestionText($questionText),
        ]);

        return hash('sha256', $key);
    }

    public function refreshQuestionFingerprint(): string
    {
        $fingerprint = self::buildQuestionFingerprint(
            $this->topic_id,
            $this->learning_objective_id,
            $this->question_type,
            $this->question
        );

        $this->question_fingerprint = $fingerprint;

        return $fingerprint;
    }

    protected static function booted(): void
    {
        static::saving(function (Question $question) {
            if (
                $question->parent_question_id === null &&
                $question->question !== null
            ) {
                $question->question_fingerprint = self::buildQuestionFingerprint(
                    $question->topic_id,
                    $question->learning_objective_id,
                    $question->question_type,
                    $question->question
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getQuestionImageUrlAttribute(): ?string
    {
        return $this->question_image
            ? asset('storage/' . $this->question_image)
            : null;
    }

    public function getCorrectAnswerImageUrlAttribute(): ?string
    {
        return $this->correct_answer_image
            ? asset('storage/' . $this->correct_answer_image)
            : null;
    }

    protected function questionImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->question_image
                ? asset('storage/' . $this->question_image)
                : null
        );
    }

    protected function correctAnswerImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->correct_answer_image
                ? asset('storage/' . $this->correct_answer_image)
                : null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | KaTeX
    |--------------------------------------------------------------------------
    */

    public function getKatexContentAttribute()
    {
        return $this->is_math
            ? $this->question
            : null;
    }

    protected function katexContent(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->is_math
                ? $this->renderKaTeX($this->question)
                : null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function topic()
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    public function learningObjective()
    {
        return $this->belongsTo(LearningObjective::class, 'learning_objective_id');
    }

    public function gradeSubject()
    {
        return $this->hasOneThrough(
            GradeSubject::class,
            Topic::class,
            'id',
            'id',
            'topic_id',
            'grade_subject_id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assessmentQuestions()
    {
        return $this->hasMany(AssessmentQuestion::class);
    }

    public function studentAnswers()
    {
        return $this->hasMany(StudentAnswer::class);
    }

    public function usages()
    {
        return $this->hasMany(QuestionUsage::class);
    }

    public function parent()
    {
        return $this->belongsTo(Question::class, 'parent_question_id');
    }

    public function subQuestions()
    {
        return $this->hasMany(Question::class, 'parent_question_id')->orderBy('id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeAssessmentEligible($query)
    {
        return $query
            ->where('status', 'approved')
            ->where('is_assessment_eligible', true);
    }

    public function scopeForLearningObjective($query, $learningObjectiveId)
    {
        return $query->where('learning_objective_id', $learningObjectiveId);
    }

    public function scopeTeacherCreated($query)
    {
        return $query->where('source', 'teacher');
    }

    public function scopeAiGenerated($query)
    {
        return $query->where('source', 'ai');
    }
}