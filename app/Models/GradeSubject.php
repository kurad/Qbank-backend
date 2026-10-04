<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeSubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'teacher_id',
        'grade_level_id',
        'subject_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)
            ->orderBy('order');
    }


    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'grade_subject_id');
    }

    public function learningPeriods(): HasMany
    {
        return $this->hasMany(LearningPeriod::class, 'grade_subject_id')
            ->orderByDesc('start_date');
    }
}
