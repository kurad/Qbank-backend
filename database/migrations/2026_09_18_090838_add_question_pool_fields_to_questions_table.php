<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->enum('source', ['teacher', 'ai', 'import'])->default('teacher')->after('created_by');
            $table->enum('status', ['draft', 'approved', 'rejected','archived'])->default('approved')->after('source');
            $table->boolean('is_assessment_eligible')->default(true)->after('status');
            $table->index(
                ['topic_id', 'learning_objective_id', 'status', 'is_assessment_eligible'],
                'questions_assessment_pool_idx'
            );

            $table->index(
                ['source', 'status'],
                'questions_source_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex('questions_assessment_pool_idx');
            $table->dropIndex('questions_source_status_idx');

            $table->dropColumn([
                'source',
                'status',
                'is_assessment_eligible',
            ]);
        });
    }
};