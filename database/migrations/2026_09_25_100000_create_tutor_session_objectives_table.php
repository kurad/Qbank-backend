<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_session_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->constrained('tutor_sessions')->cascadeOnDelete();
            $table->foreignId('learning_objective_id')->constrained('learning_objectives')->cascadeOnDelete();
            $table->unsignedInteger('objective_order')->default(1);
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('correct_attempts')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tutor_session_id', 'learning_objective_id'], 'tso_session_objective_unique');
            $table->index(['tutor_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_session_objectives');
    }
};
