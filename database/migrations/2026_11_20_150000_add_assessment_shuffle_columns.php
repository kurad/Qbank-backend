<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
        });
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->json('question_order')->nullable();
            $table->json('option_order')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('student_assessments', fn (Blueprint $table) => $table->dropColumn(['question_order', 'option_order']));
        Schema::table('assessments', fn (Blueprint $table) => $table->dropColumn(['shuffle_questions', 'shuffle_options']));
    }
};
