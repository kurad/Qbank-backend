<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_material_visuals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_material_id')
                ->constrained('course_materials')
                ->cascadeOnDelete();

            $table->foreignId('course_material_page_id')
                ->constrained('course_material_pages')
                ->cascadeOnDelete();

            $table->string('type', 50)->default('image');

            $table->string('file_path');

            $table->string('file_name')->nullable();

            $table->string('mime_type', 100)->nullable();

            $table->text('caption')->nullable();

            $table->longText('ocr_text')->nullable();

            $table->longText('ai_description')->nullable();

            $table->json('metadata')->nullable();

            $table->unsignedInteger('sort_order')->default(1);

            $table->timestamps();

            $table->index(
                ['course_material_id', 'course_material_page_id'],
                'cm_visuals_material_page_idx'
            );

            $table->index(
                ['course_material_id', 'type'],
                'cm_visuals_material_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_visuals');
    }
};