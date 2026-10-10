<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionnaireImportItem extends Model
{
    protected $fillable = [
        'questionnaire_import_id',
        'source_order',
        'source_reference',
        'question_type',
        'question',
        'options',
        'correct_answer',
        'marks',
        'difficulty_level',
        'is_math',
        'is_chemistry',
        'explanation',
        'unit_id',
        'topic_id',
        'learning_objective_id',
        'mapping_confidence',
        'duplicate_question_id',
        'status',
        'created_question_id',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'array',
        'marks' => 'decimal:2',
        'mapping_confidence' => 'decimal:2',
        'is_math' => 'boolean',
        'is_chemistry' => 'boolean',
    ];

    public function questionnaireImport(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireImport::class);
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
        return $this->belongsTo(LearningObjective::class);
    }

    public function duplicateQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'duplicate_question_id');
    }

    public function createdQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'created_question_id');
    }
}
