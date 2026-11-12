<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_assessment_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_assessment_id')->unique()->constrained('student_assessments')->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedInteger('page_index')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_assessment_drafts');
    }
};
