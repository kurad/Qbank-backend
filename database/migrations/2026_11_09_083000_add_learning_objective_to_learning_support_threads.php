<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_support_threads', function (Blueprint $table) {
            $table
                ->foreignId('learning_objective_id')
                ->nullable()
                ->after('topic_id')
                ->constrained('learning_objectives')
                ->nullOnDelete();

            $table->index(
                ['student_id', 'learning_objective_id'],
                'learning_support_student_objective_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('learning_support_threads', function (Blueprint $table) {
            $table->dropIndex('learning_support_student_objective_idx');
            $table->dropConstrainedForeignId('learning_objective_id');
        });
    }
};
