<?php

namespace App\Models;

use App\Models\TutorResponseEvaluation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TutorSessionObjective extends Model
{
    use HasFactory;

    protected $fillable = [
        'tutor_session_id',
        'learning_objective_id',
        'objective_order',
        'status',
        'started_at',
        'completed_at',
        'attempts',
        'correct_attempts',
        'notes',
    ];

    protected $casts = [
        'objective_order' => 'integer',
        'attempts' => 'integer',
        'correct_attempts' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TutorSession::class, 'tutor_session_id');
    }

    public function learningObjective(): BelongsTo
    {
        return $this->belongsTo(LearningObjective::class);
    }
    public function responseEvaluations()
    {
        return $this->hasMany(
            TutorResponseEvaluation::class,
            'tutor_session_objective_id'
        );
    }
}
