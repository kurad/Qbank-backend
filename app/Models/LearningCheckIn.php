<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningCheckIn extends Model
{
    protected $fillable = [
        'thread_id', 'scheduled_at', 'status', 'teacher_notes',
        'student_notes', 'follow_up_required', 'completed_at',
    ];
    protected $casts = [
        'scheduled_at' => 'datetime', 'completed_at' => 'datetime',
        'follow_up_required' => 'boolean',
    ];
    public function thread() { return $this->belongsTo(LearningSupportThread::class, 'thread_id'); }
}
