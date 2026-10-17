<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningSupportThread extends Model
{
    protected $fillable = [
        'student_id', 'teacher_id', 'learning_period_id', 'topic_id',
        'type', 'status', 'subject', 'last_message_at',
    ];

    protected $casts = ['last_message_at' => 'datetime'];

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function teacher() { return $this->belongsTo(User::class, 'teacher_id'); }
    public function learningPeriod() { return $this->belongsTo(LearningPeriod::class); }
    public function topic() { return $this->belongsTo(Topic::class); }
    public function messages() { return $this->hasMany(LearningSupportMessage::class, 'thread_id'); }
    public function checkIns() { return $this->hasMany(LearningCheckIn::class, 'thread_id'); }
}
