<?php

namespace App\Models;

use App\Models\Assessment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_subject_id',
        'name',
        'description',
        'order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function gradeSubject(): BelongsTo
    {
        return $this->belongsTo(GradeSubject::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)
            ->orderBy('id');
    }
    public function assessments()
    {
        return $this->belongsToMany(
            Assessment::class,
            'assessment_unit',
            'unit_id',
            'assessment_id'
        )->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}