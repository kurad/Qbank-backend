<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearnerFormativeQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'learner_formative_attempt_id',
        'question_id',
        'display_order',
        'option_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'option_order' => 'array',
    ];

    public function attempt()
    {
        return $this->belongsTo(
            LearnerFormativeAttempt::class,
            'learner_formative_attempt_id'
        );
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
