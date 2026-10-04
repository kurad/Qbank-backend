<?php

namespace App\Models;

use App\Models\LearningPeriodTopic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'grade_subject_id',
        'title',
        'description',
        'start_date',
        'end_date',
        'status',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'published_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function gradeSubject(): BelongsTo
    {
        return $this->belongsTo(GradeSubject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(LearningPeriodTopic::class)
            ->orderBy('display_order');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isCurrent(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        $today = now()->toDateString();

        return $this->start_date->toDateString() <= $today
            && $this->end_date->toDateString() >= $today;
    }

    public function isUpcoming(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        return $this->start_date->toDateString() > now()->toDateString();
    }

    public function isPast(): bool
    {
        return $this->end_date->toDateString() < now()->toDateString();
    }
}