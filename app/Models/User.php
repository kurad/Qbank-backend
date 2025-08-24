<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Quiz;
use App\Models\School;
use App\Models\QuizSubmission;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // e.g., 'teacher', 'student',
        'school_id',
        'google_id',
    ];
    // User belongs to a school
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];


    public function createdAssessment()
    {
        return $this->hasMany(Assessment::class, 'creator_id');
    }
    public function studentAssessments()
    {
        return $this->hasMany(StudentAssessment::class);
    }
    public function studentAnswers()
    {
        return $this->hasMany(StudentAnswer::class);
    }

}
