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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->onDelete('cascade');
            $table->text('question');
            $table->enum('question_type', ['mcq', 'true_false', 'short_answer'])->default('mcq');
            $table->json('options')->nullable(); // {'A': 'Option 1', 'B': 'Option 2'...}
            $table->string('correct_answer'); // e.g. 'A' or json for multiple answers
            $table->decimal('marks', 2, 1)->default(1.00); // Marks for the question
            $table->enum('difficulty_level', ['remembering', 'understanding', 'applying', 'analyzing', 'evaluating', 'creating'])->default('understanding');
            $table->boolean('is_math')->default(false);
            $table->boolean('multiple_answers')->default(false);
            $table->boolean('is_required')->default(false);
            $table->text('explanation')->nullable();
            $table->string('question_image')->nullable(); // Path to the question image
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
