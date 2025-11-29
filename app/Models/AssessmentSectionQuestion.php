<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssessmentSectionQuestion extends Model
{
    use HasFactory;
    protected $fillable = [
        'assessment_section_id',
        'question_id',
        'ordering',
    ];
}
