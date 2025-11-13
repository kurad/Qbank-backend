<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionUsage extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = [
        'question_id',
        'assessment_id',
        'used_at',
    ];
    public function question()
    {
        return $this->belongsTo(Question::class);
    }
    public function assessment()
    {
        return $this->belongsTo(Assessment::class);
    }
}
