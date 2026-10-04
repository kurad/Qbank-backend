<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_material_pages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_material_id')
                ->constrained('course_materials')
                ->cascadeOnDelete();

            $table->unsignedInteger('page_number');

            $table->longText('text')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique([
                'course_material_id',
                'page_number',
            ]);

            $table->index([
                'course_material_id',
                'page_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_pages');
    }
};