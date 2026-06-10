<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PracticePaper extends Model
{
    protected $fillable = [
        'teacher_id',
        'subject_id',
        'title',
        'description',
        'academic_year',
        'term',
        'original_file_path',
        'watermarked_file_path',
        'file_name',
        'file_type',
        'status',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}