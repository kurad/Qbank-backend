<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'unit_id',
        'topic_id',
        'title',
        'description',
        'original_file_path',
        'file_name',
        'file_type',
        'file_hash',
        'extracted_text',
        'extraction_status',
        'extracted_at',
        'extraction_error',
        'status',
        'scope',
        'version',
        'approved_by',
        'approved_at',
        'visual_extraction_status',
        'visual_extracted_at',
        'visual_extraction_error',
        'processing_status',
        'processing_stage',
        'processing_progress',
        'processing_error',
        'processing_started_at',
        'processing_completed_at',
    ];

    protected $hidden = [
        'extracted_text',
        'extraction_error',

    ];

    protected $casts = [
        'extracted_at' => 'datetime',
        'visual_extracted_at' => 'datetime',
        'processing_progress' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function knowledgeChunks()
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
    public function curriculumAnalyses()
    {
        return $this->hasMany(CurriculumAnalysis::class);
    }
    public function pages()
    {
        return $this->hasMany(CourseMaterialPage::class)
            ->orderBy('page_number');
    }

    public function visuals()
    {
        return $this->hasMany(CourseMaterialVisual::class)
            ->orderBy('course_material_page_id')
            ->orderBy('sort_order');
    }
}
