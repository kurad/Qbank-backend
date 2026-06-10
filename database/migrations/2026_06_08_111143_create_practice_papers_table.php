<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('practice_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('academic_year')->nullable(); // 2025-2026
            $table->string('term')->nullable(); // Term 1, Term 2, Term 3
            $table->string('original_file_path')->nullable();
            $table->string('watermarked_file_path');
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->enum('status', ['draft', 'published', 'shared'])->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_papers');
    }
};
