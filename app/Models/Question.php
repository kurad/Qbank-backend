<?php

namespace App\Models;

use App\Models\Quiz;
use App\Models\User;
use App\Models\Topic;
use App\Models\GradeLevel;
use Attribute;
use Dom\Attr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'topic_id',
        'question_type',
        'question',
        'options',
        'correct_answer',
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
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'array',
        'is_math' => 'boolean',
        'is_chemistry' => 'boolean',
        'multiple_answers' => 'boolean',
        'is_required' => 'boolean',
    ];
    protected $appends = ['question_image_url'];

    protected function questionImageUrl(): Attribute
    {
        return Attribute::make(
            get:fn () => $this->question_image ? asset('storage/' . $this->question_image) : null
        );
    }
    public function getKatexContentAttribute()
    {
        // Return question content as-is (or process HTML/KaTeX here)
        return $this->is_math ? $this->question : null;
    }
    protected function katexContent(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->is_math ? $this->renderKaTeX($this->question) : null
        );
    }
     public function getQuestionImageUrlAttribute()
    {
        return $this->question_image ? asset('storage/' . $this->question_image) : null;
    }

    public function topic()
{
    return $this->belongsTo(Topic::class);
}

public function gradeSubject()
{
    return $this->hasOneThrough(GradeSubject::class, Topic::class);
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
    return $this->hasMany(Question::class, 'parent_question_id');
}
}