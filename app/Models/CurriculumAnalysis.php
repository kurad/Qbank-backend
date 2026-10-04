<?php

namespace App\Models;

use App\Models\CurriculumAnalysisTopic;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurriculumAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_material_id',
        'unit_id',
        'teacher_id',
        'status',
        'version',
        'instruction',
        'parent_analysis_id',
        'analysis_error',
        'analyzed_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'analyzed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function courseMaterial()
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function topics()
    {
        return $this->hasMany(CurriculumAnalysisTopic::class)
            ->orderBy('order');
    }
    public function parentAnalysis()
    {
        return $this->belongsTo(
            CurriculumAnalysis::class,
            'parent_analysis_id'
        );
    }

    public function childAnalyses()
    {
        return $this->hasMany(
            CurriculumAnalysis::class,
            'parent_analysis_id'
        );
    }
}
