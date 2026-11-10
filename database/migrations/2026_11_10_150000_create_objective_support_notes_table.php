<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('objective_support_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_objective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users');
            $table->longText('content');
            $table->timestamps();
            $table->unique(['learning_objective_id', 'grade_subject_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('objective_support_notes'); }
};
