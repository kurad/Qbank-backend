<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tutor_conversation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->constrained('tutor_sessions')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id');
            $table->unsignedInteger('message_count');
            $table->boolean('is_partial')->default(false);
            $table->text('summary');
            $table->json('findings');
            $table->timestamps();
            $table->index(['tutor_session_id','created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('tutor_conversation_reviews'); }
};
