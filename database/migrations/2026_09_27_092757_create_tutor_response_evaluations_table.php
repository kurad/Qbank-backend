<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_response_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_session_id')->constrained('tutor_sessions')->cascadeOnDelete();
            $table->foreignId('tutor_session_objective_id')->nullable()->constrained('tutor_session_objectives')->nullOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->text('student_response');
            $table->string('classification', 30);
            $table->boolean('correct')->default(false);
            $table->unsignedTinyInteger('understanding_score')->default(0);
            $table->text('feedback')->nullable();
            $table->text('evidence')->nullable();
            $table->text('misconception')->nullable();
            $table->timestamps();
            $table->index([
                'tutor_session_id',
                'tutor_session_objective_id',
            ], 'tutor_session_objective_idx');

            $table->index([
                'student_id',
                'created_at',
            ]);

            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_response_evaluations');
    }
};