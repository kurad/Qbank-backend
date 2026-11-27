<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('assessment_groups', 'locked_at')) {
            Schema::table('assessment_groups', function (Blueprint $table) {
                // When set, students of this class who have not started can no
                // longer open the assessment.
                $table->timestamp('locked_at')->nullable();
                $table->unsignedBigInteger('locked_by')->nullable();
            });
        }

        if (!Schema::hasColumn('student_assessments', 'access_override')) {
            Schema::table('student_assessments', function (Blueprint $table) {
                // Teacher gave this student access although the assessment is locked.
                $table->boolean('access_override')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assessment_groups', 'locked_at')) {
            Schema::table('assessment_groups', fn (Blueprint $t) => $t->dropColumn(['locked_at', 'locked_by']));
        }
        if (Schema::hasColumn('student_assessments', 'access_override')) {
            Schema::table('student_assessments', fn (Blueprint $t) => $t->dropColumn('access_override'));
        }
    }
};
