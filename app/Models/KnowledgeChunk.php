<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_material_id',
        'subject_id',
        'unit_id',
        'topic_id',
        'chunk_index',
        'content',
        'character_count',
        'token_count',
        'metadata',
        'status',
        'processing_error',
        'course_material_page_id',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function courseMaterial()
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function page()
    {
        return $this->belongsTo(\App\Models\CourseMaterialPage::class, 'course_material_page_id');
    }
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }
}
