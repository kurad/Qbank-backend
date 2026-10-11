<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('learning_period_id')->constrained('learning_periods')->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();

            $table
                ->foreignId('parent_attempt_id')
                ->nullable()
                ->constrained('practice_attempts')
                ->nullOnDelete();

            $table
                ->enum('mode', ['full', 'retry_incorrect'])
                ->default('full');

            $table
                ->enum('status', ['in_progress', 'completed', 'abandoned'])
                ->default('in_progress');

            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->decimal('total_marks', 10, 2)->default(0);
            $table->decimal('earned_marks', 10, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'topic_id', 'completed_at']);
        });

        Schema::create('practice_attempt_answers', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('practice_attempt_id')
                ->constrained('practice_attempts')
                ->cascadeOnDelete();

            $table
                ->foreignId('question_id')
                ->constrained('questions')
                ->cascadeOnDelete();

            $table->json('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->boolean('review_only')->default(false);
            $table->decimal('marks_awarded', 10, 2)->default(0);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['practice_attempt_id', 'question_id']);
            $table->index(['question_id', 'is_correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_attempt_answers');
        Schema::dropIfExists('practice_attempts');
    }
};
