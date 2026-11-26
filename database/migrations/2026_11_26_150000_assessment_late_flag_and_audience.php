<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'all'      = every student in the class (existing behaviour)
        // 'selected' = only students who hold a student_assessments row
        if (!Schema::hasColumn('assessment_groups', 'audience')) {
            Schema::table('assessment_groups', function (Blueprint $table) {
                $table->string('audience', 16)->default('all');
            });
        }

        // Set once, when the student submits. Never recalculated afterwards
        // (changing the due date later must not rewrite history).
        if (!Schema::hasColumn('student_assessments', 'is_late')) {
            Schema::table('student_assessments', function (Blueprint $table) {
                $table->boolean('is_late')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assessment_groups', 'audience')) {
            Schema::table('assessment_groups', fn (Blueprint $t) => $t->dropColumn('audience'));
        }
        if (Schema::hasColumn('student_assessments', 'is_late')) {
            Schema::table('student_assessments', fn (Blueprint $t) => $t->dropColumn('is_late'));
        }
    }
};
