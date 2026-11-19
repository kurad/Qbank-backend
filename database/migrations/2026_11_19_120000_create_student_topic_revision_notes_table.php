<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('student_topic_revision_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('learning_period_id')->constrained('learning_periods')->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('source_session_id')->nullable()->constrained('tutor_sessions')->nullOnDelete();
            $table->longText('content');
            $table->timestamps();
            $table->unique(['student_id', 'learning_period_id', 'topic_id'], 'student_topic_revision_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('student_topic_revision_notes'); }
};
