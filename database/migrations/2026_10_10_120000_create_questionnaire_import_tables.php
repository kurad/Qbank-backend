<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('grade_subject_id')->constrained('grade_subjects')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->enum('status', [
                'queued',
                'processing',
                'review',
                'completed',
                'failed',
            ])->default('queued');
            $table->unsignedInteger('question_count')->default(0);
            $table->longText('extracted_text')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['grade_subject_id', 'status']);
        });

        Schema::create('questionnaire_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_import_id')
                ->constrained('questionnaire_imports')
                ->cascadeOnDelete();

            $table->unsignedInteger('source_order')->default(1);
            $table->string('source_reference')->nullable();

            $table->enum('question_type', [
                'mcq',
                'true_false',
                'short_answer',
                'fill_blank',
                'matching',
                'open_ended',
            ]);

            $table->text('question');
            $table->json('options')->nullable();
            $table->json('correct_answer')->nullable();
            $table->decimal('marks', 7, 2)->nullable();
            $table->enum('difficulty_level', [
                'remembering',
                'understanding',
                'applying',
                'analyzing',
                'evaluating',
                'creating',
            ])->default('understanding');

            $table->boolean('is_math')->default(false);
            $table->boolean('is_chemistry')->default(false);
            $table->text('explanation')->nullable();

            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->foreignId('learning_objective_id')->nullable()
                ->constrained('learning_objectives')
                ->nullOnDelete();

            $table->decimal('mapping_confidence', 5, 2)->nullable();

            $table->foreignId('duplicate_question_id')
                ->nullable()
                ->constrained('questions')
                ->nullOnDelete();

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'imported',
            ])->default('pending');

            $table->foreignId('created_question_id')
                ->nullable()
                ->constrained('questions')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['questionnaire_import_id', 'status'], 'questionnaire_items_status_idx');
            $table->index(['topic_id', 'learning_objective_id'], 'questionnaire_items_mapping_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_import_items');
        Schema::dropIfExists('questionnaire_imports');
    }
};
