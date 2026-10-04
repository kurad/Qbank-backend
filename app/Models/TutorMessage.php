<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TutorMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'tutor_session_id',
        'role',
        'message_type',
        'content',
        'metadata',
    ];
    protected $casts = [
        'metadata' => 'array',
    ];

    public function session()
    {
        return $this->belongsTo(TutorSession::class, 'tutor_session_id');
    }
}
