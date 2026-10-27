<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnglishWritingDraft extends Model
{
    public function teacherReviews()
    {
        return $this->hasMany(EnglishWritingReview::class, 'english_writing_draft_id');
    }

    protected $fillable = ['tutor_session_id', 'student_id', 'revision_number', 'title', 'body', 'feedback'];
}
