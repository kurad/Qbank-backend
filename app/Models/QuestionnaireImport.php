<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionnaireImport extends Model
{
    protected $fillable = [
        'user_id',
        'grade_subject_id',
        'original_name',
        'file_path',
        'mime_type',
        'status',
        'question_count',
        'extracted_text',
        'error_message',
    ];

    protected $casts = [
        'question_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gradeSubject(): BelongsTo
    {
        return $this->belongsTo(GradeSubject::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuestionnaireImportItem::class)
            ->orderBy('source_order')
            ->orderBy('id');
    }
}
