<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'email',
        'token_hash',
        'invited_by',
        'status',
        'expires_at',
        'accepted_by',
        'accepted_at',
        'previous_school_id',
        'transfer_confirmed',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'transfer_confirmed' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function previousSchool()
    {
        return $this->belongsTo(School::class, 'previous_school_id');
    }

    public function isUsable(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture();
    }
}
