<?php

namespace App\Models;

use App\Models\School;
use App\Models\StudentAnswer;
use App\Models\TutorResponseEvaluation;
use App\Notifications\VerifyEmailForSpa;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use MustVerifyEmailTrait;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'school_id',
        'status',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailForSpa);
    }

    /*
    |--------------------------------------------------------------------------
    | School
    |--------------------------------------------------------------------------
    */

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
    public function teachingAreas(): HasMany
    {
        return $this->hasMany(GradeSubject::class, 'teacher_id');
    }

    public function createdGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'created_by');
    }

    public function subjectsCreated(): HasMany
    {
        return $this->hasMany(Subject::class, 'created_by');
    }
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            Group::class,
            'group_students',
            'student_id',
            'group_id'
        );
    }

    public function createdAssessment(): HasMany
    {
        return $this->hasMany(Assessment::class, 'creator_id');
    }

    public function studentAssessments(): HasMany
    {
        return $this->hasMany(StudentAssessment::class, 'student_id');
    }

    public function studentAnswers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class, 'student_id');
    }
    public function tutorSessions()
    {
        return $this->hasMany(TutorSession::class, 'student_id');
    }
    public function tutorResponseEvaluations()
    {
        return $this->hasMany(
            TutorResponseEvaluation::class,
            'student_id'
        );
    }
}
