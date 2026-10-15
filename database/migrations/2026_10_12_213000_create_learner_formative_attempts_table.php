<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_formative_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('learning_period_id')->constrained('learning_periods')->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            $table->foreignId('student_assessment_id')->nullable()->constrained('student_assessments')->nullOnDelete();
            $table->string('status', 32)->default('eligible');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage', 64)->nullable();
            $table->string('message')->nullable();
            $table->string('seed', 64);
            $table->unsignedInteger('question_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'learning_period_id'], 'learner_formative_student_period_unique');
            $table->index(['learning_period_id', 'status']);
        });

        Schema::create('learner_formative_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_formative_attempt_id')
                ->constrained('learner_formative_attempts')
                ->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->unsignedInteger('display_order');
            $table->json('option_order')->nullable();
            $table->timestamps();

            $table->unique(
                ['learner_formative_attempt_id', 'question_id'],
                'learner_formative_attempt_question_unique'
            );
            $table->unique(
                ['learner_formative_attempt_id', 'display_order'],
                'learner_formative_attempt_order_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_formative_questions');
        Schema::dropIfExists('learner_formative_attempts');
    }
};
