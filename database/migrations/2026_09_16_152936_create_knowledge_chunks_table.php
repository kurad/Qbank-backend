<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_material_id')->constrained('course_materials')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->unsignedInteger('character_count')->default(0);
            $table->unsignedInteger('token_count')->nullable();
            $table->json('metadata')->nullable();
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->unique(['course_material_id', 'chunk_index',]);
            $table->index(['subject_id', 'unit_id', 'topic_id',]);
            $table->index(['course_material_id', 'status',]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};