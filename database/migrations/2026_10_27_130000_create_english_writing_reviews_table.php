<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('english_writing_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('english_writing_draft_id')->constrained('english_writing_drafts')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('needs_revision');
            $table->text('comment');
            $table->timestamps();
            $table->unique(['english_writing_draft_id', 'teacher_id'], 'english_review_teacher_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('english_writing_reviews'); }
};
