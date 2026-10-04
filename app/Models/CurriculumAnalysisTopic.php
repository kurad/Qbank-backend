<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurriculumAnalysisTopic extends Model
{
    use HasFactory;

    protected $fillable = [
        'curriculum_analysis_id',
        'name',
        'description',
        'order',
        'status',
    ];

    public function analysis()
    {
        return $this->belongsTo(
            CurriculumAnalysis::class,
            'curriculum_analysis_id'
        );
    }

    public function objectives()
    {
        return $this->hasMany(CurriculumAnalysisObjective::class)
            ->orderBy('order');
    }
}