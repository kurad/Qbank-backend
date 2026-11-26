<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * 1. learning_objectives.status was created as ENUM('active','inactive'),
         *    but approving a revised (V2) curriculum archives the previous
         *    objectives with status = 'archived'. Under MySQL strict mode that
         *    update fails with "Data truncated" and rolls the approval back.
         */
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "ALTER TABLE learning_objectives MODIFY COLUMN status " .
                "ENUM('active','inactive','archived') NOT NULL DEFAULT 'active'"
            );
        }

        /*
         * 2. Analysis-level transparency: was the source cut off, and which
         *    AI-proposed objectives were refused because the material did not
         *    contain enough to teach them.
         */
        Schema::table('curriculum_analyses', function (Blueprint $table) {
            if (!Schema::hasColumn('curriculum_analyses', 'source_truncated')) {
                $table->boolean('source_truncated')->default(false)->after('analysis_error');
            }

            if (!Schema::hasColumn('curriculum_analyses', 'source_chars_total')) {
                $table->unsignedInteger('source_chars_total')->nullable()->after('source_truncated');
            }

            if (!Schema::hasColumn('curriculum_analyses', 'source_chars_used')) {
                $table->unsignedInteger('source_chars_used')->nullable()->after('source_chars_total');
            }

            if (!Schema::hasColumn('curriculum_analyses', 'dropped_objectives')) {
                $table->json('dropped_objectives')->nullable()->after('source_chars_used');
            }
        });

        /*
         * 3. The passage of the teacher's material that supports each
         *    proposed objective (shown to the teacher so they can verify it).
         */
        Schema::table('curriculum_analysis_objectives', function (Blueprint $table) {
            if (!Schema::hasColumn('curriculum_analysis_objectives', 'source_excerpt')) {
                $table->text('source_excerpt')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_analysis_objectives', function (Blueprint $table) {
            if (Schema::hasColumn('curriculum_analysis_objectives', 'source_excerpt')) {
                $table->dropColumn('source_excerpt');
            }
        });

        Schema::table('curriculum_analyses', function (Blueprint $table) {
            foreach (['dropped_objectives', 'source_chars_used', 'source_chars_total', 'source_truncated'] as $column) {
                if (Schema::hasColumn('curriculum_analyses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('learning_objectives')
                ->where('status', 'archived')
                ->update(['status' => 'inactive']);

            DB::statement(
                "ALTER TABLE learning_objectives MODIFY COLUMN status " .
                "ENUM('active','inactive') NOT NULL DEFAULT 'active'"
            );
        }
    }
};
