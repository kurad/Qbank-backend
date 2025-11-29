<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'title',
        'instruction',
        'ordering',
    ];
    public $timestamps = false;

    public function questions()
    {
        return $this->belongsToMany(Question::class, 'assessment_section_questions')->withPivot('ordering')->orderBy('assessment_section_questions.ordering');
    }

    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }
}
