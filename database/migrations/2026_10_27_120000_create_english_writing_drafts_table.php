<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('english_writing_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->constrained('tutor_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('title', 160)->nullable();
            $table->longText('body');
            $table->longText('feedback')->nullable();
            $table->timestamps();
            $table->unique(['tutor_session_id', 'revision_number'], 'english_draft_revision_unique');
            $table->index(['student_id', 'tutor_session_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('english_writing_drafts');
    }
};
