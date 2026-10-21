<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolAdminInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'email',
        'token_hash',
        'invited_by',
        'status',
        'expires_at',
        'accepted_by',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
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

    public function isUsable(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture();
    }
}
