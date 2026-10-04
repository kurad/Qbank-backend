<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_learning_objective', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('learning_objective_id')->constrained('learning_objectives')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'learning_objective_id'],'assessment_lo_unique');
            $table->index('learning_objective_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_learning_objective');
    }
};
