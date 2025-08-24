<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_name',
        'district',
        'email',
        'school_code',
    ];

    // Boot method to auto-generate unique school_code
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($school) {
            if (empty($school->school_code)) {
                do {
                    $code = strtoupper(bin2hex(random_bytes(3)));
                } while (self::where('school_code', $code)->exists());
                $school->school_code = $code;
            }
        });
    }
}
