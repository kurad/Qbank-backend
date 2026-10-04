<?php

namespace App\Models;

use App\Models\Assessment;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningObjective extends Model
{
    use HasFactory;

    protected $fillable = [
        'topic_id',
        'code',
        'objective',
        'description',
        'order',
        'status',
        'created_by',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function assessments()
    {
        return $this->belongsToMany(
            Assessment::class,
            'assessment_learning_objective',
            'learning_objective_id',
            'assessment_id'
        )->withTimestamps();
    }
    public function questions()
    {
        return $this->hasMany(
            Question::class,
            'learning_objective_id'
        );
    }
}
