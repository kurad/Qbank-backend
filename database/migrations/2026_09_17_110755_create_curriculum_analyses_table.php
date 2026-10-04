<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_analyses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_material_id')
                ->constrained('course_materials')
                ->cascadeOnDelete();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->cascadeOnDelete();

            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('status', [
                'pending',
                'processing',
                'ready',
                'approved',
                'failed',
            ])->default('pending');

            $table->unsignedInteger('version')->default(1);

            $table->text('analysis_error')->nullable();

            $table->timestamp('analyzed_at')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index([
                'course_material_id',
                'status',
            ]);

            $table->index([
                'unit_id',
                'status',
            ]);

            $table->index([
                'teacher_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_analyses');
    }
};