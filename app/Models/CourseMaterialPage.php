<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseMaterialPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_material_id',
        'page_number',
        'text',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function courseMaterial(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function visuals(): HasMany
    {
        return $this->hasMany(CourseMaterialVisual::class)
            ->orderBy('sort_order');
    }
}