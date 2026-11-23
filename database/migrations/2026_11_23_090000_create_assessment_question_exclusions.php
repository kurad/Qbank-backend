<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->decimal('original_max_score', 10, 2)->nullable();
        });
        Schema::create('assessment_question_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->boolean('is_excluded')->default(true);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['assessment_id', 'question_id'], 'assessment_question_excl_unique');
        });
        Schema::create('assessment_question_exclusion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->boolean('is_excluded');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('assessment_question_exclusion_events');
        Schema::dropIfExists('assessment_question_exclusions');
        Schema::table('student_assessments', fn (Blueprint $table) => $table->dropColumn('original_max_score'));
    }
};
