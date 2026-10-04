<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseMaterialVisual extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_material_id',
        'course_material_page_id',
        'type',
        'file_path',
        'file_name',
        'mime_type',
        'caption',
        'ocr_text',
        'ai_description',
        'metadata',
        'sort_order',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function courseMaterial(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(
            CourseMaterialPage::class,
            'course_material_page_id'
        );
    }
}