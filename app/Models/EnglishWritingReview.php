<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnglishWritingReview extends Model
{
    protected $fillable = ['english_writing_draft_id', 'teacher_id', 'comment', 'status'];
    public function teacher() { return $this->belongsTo(User::class, 'teacher_id'); }
    public function draft() { return $this->belongsTo(EnglishWritingDraft::class, 'english_writing_draft_id'); }
}
