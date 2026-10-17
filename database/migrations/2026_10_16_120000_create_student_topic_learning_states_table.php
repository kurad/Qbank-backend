<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_topic_learning_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('learning_period_id')->constrained('learning_periods')->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->string('progression_status', 30)->default('not_started');
            $table->string('mastery_status', 30)->default('unknown');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('best_score')->nullable();
            $table->unsignedTinyInteger('last_score')->nullable();
            $table->boolean('move_on_chosen')->default(false);
            $table->string('move_on_reason', 80)->nullable();
            $table->timestamp('move_on_at')->nullable();
            $table->timestamp('mastered_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'learning_period_id', 'topic_id'], 'student_topic_learning_state_unique');
            $table->index(['learning_period_id', 'mastery_status']);
            $table->index(['student_id', 'progression_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_topic_learning_states');
    }
};
