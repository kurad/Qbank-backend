<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('learning_objective_id')->nullable()->after('topic_id')->constrained('learning_objectives')->nullOnDelete();
            $table->index(
                ['topic_id', 'learning_objective_id'],
                'questions_topic_objective_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['learning_objective_id']);
            $table->dropIndex('questions_topic_objective_idx');
            $table->dropColumn('learning_objective_id');
        });
    }
};