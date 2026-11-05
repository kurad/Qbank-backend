<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseMaterialSupportingSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_material_id',
        'teacher_id',
        'title',
        'url',
        'domain',
        'status',
        'cached_title',
        'cached_content',
        'content_type',
        'validated_at',
        'last_error',
    ];

    protected $hidden = [
        'cached_content',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
    ];

    public function courseMaterial()
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
