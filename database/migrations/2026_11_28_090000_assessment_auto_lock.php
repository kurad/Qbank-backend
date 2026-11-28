<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('assessments', 'auto_lock')) {
            Schema::table('assessments', function (Blueprint $table) {
                // Close the assessment to students who have not started once
                // the due date (plus grace) has passed.
                $table->boolean('auto_lock')->default(false);
                $table->unsignedSmallInteger('auto_lock_grace_hours')->default(0);
            });
        }

        if (!Schema::hasColumn('assessment_groups', 'unlocked_at')) {
            Schema::table('assessment_groups', function (Blueprint $table) {
                // Teacher reopened this class after an automatic lock, so the
                // automatic lock must not close it again.
                $table->timestamp('unlocked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assessments', 'auto_lock')) {
            Schema::table('assessments', fn (Blueprint $t) => $t->dropColumn(['auto_lock', 'auto_lock_grace_hours']));
        }
        if (Schema::hasColumn('assessment_groups', 'unlocked_at')) {
            Schema::table('assessment_groups', fn (Blueprint $t) => $t->dropColumn('unlocked_at'));
        }
    }
};
