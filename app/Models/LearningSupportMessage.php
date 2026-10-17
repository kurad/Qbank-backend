<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningSupportMessage extends Model
{
    protected $fillable = ['thread_id', 'sender_id', 'message', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];
    public function thread() { return $this->belongsTo(LearningSupportThread::class, 'thread_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
}
