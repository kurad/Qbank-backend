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
        Schema::create('student_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_assessment_id');
            $table->unsignedBigInteger('question_id');
            $table->text('answer');
            $table->boolean('is_correct');
            $table->integer('points_earned')->default(0);
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->foreign('student_assessment_id')->references('id')->on('student_assessments')->onDelete('cascade');
            $table->foreign('question_id')->references('id')->on('questions')->onDelete('cascade');
            $table->unique(['student_assessment_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_answers');
    }
};
