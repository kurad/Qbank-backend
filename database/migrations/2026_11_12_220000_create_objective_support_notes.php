<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The existing 2026-11-10 migration owns this table. Never recreate it.
        if (!Schema::hasTable('objective_support_notes')) {
            throw new \RuntimeException('Existing objective_support_notes table missing. Run the 2026-11-10 migration first.');
        }
        foreach (['learning_objective_id','grade_subject_id','teacher_id','content'] as $column) {
            if (!Schema::hasColumn('objective_support_notes', $column)) {
                throw new \RuntimeException("Existing objective_support_notes lacks {$column}; inspect schema before migration.");
            }
        }
        if (!Schema::hasColumn('objective_support_notes', 'approved_by')) {
            Schema::table('objective_support_notes', function (Blueprint $table) {
                $table->unsignedBigInteger('approved_by')->nullable();
            });
        }
        if (!Schema::hasColumn('objective_support_notes', 'approved_at')) {
            Schema::table('objective_support_notes', function (Blueprint $table) {
                $table->timestamp('approved_at')->nullable();
            });
        }
    }
    public function down(): void
    {
        // Preserve the original table and all note records on rollback.
        if (!Schema::hasTable('objective_support_notes')) return;
        if (Schema::hasColumn('objective_support_notes', 'approved_by')) {
            Schema::table('objective_support_notes', fn (Blueprint $table) => $table->dropColumn('approved_by'));
        }
        if (Schema::hasColumn('objective_support_notes', 'approved_at')) {
            Schema::table('objective_support_notes', fn (Blueprint $table) => $table->dropColumn('approved_at'));
        }
    }
};
