<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_answer_mark_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_answer_id')->constrained('student_answers')->cascadeOnDelete();
            $table->foreignId('student_assessment_id')->constrained('student_assessments')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('previous_points', 8, 2)->default(0);
            $table->decimal('new_points', 8, 2)->default(0);
            $table->boolean('previous_is_correct')->nullable();
            $table->boolean('new_is_correct')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index(['student_assessment_id', 'student_answer_id'], 'answer_mark_review_attempt_answer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answer_mark_reviews');
    }
};
