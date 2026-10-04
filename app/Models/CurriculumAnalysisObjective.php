<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurriculumAnalysisObjective extends Model
{
    use HasFactory;

    protected $fillable = [
        'curriculum_analysis_topic_id',
        'code',
        'objective',
        'description',
        'order',
        'status',
    ];

    public function topic()
    {
        return $this->belongsTo(
            CurriculumAnalysisTopic::class,
            'curriculum_analysis_topic_id'
        );
    }
}