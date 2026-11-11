<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            $table->decimal('points_earned', 8, 2)->nullable()->default(null)->change();
            $table->boolean('is_correct')->nullable()->change();
        });
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Explicitly backfill still-pending rows before restoring NOT NULL.
        // Never silently convert ungraded submissions into zero marks on rollback.
        if (\Illuminate\Support\Facades\DB::table('student_answers')->whereNull('points_earned')->orWhereNull('is_correct')->exists()) {
            throw new \RuntimeException('Resolve pending student answers before rolling back this migration.');
        }
        if (\Illuminate\Support\Facades\DB::table('student_assessments')->whereNull('score')->exists()) {
            throw new \RuntimeException('Resolve pending assessment scores before rolling back.');
        }
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->default(0)->nullable(false)->change();
        });
        Schema::table('student_answers', function (Blueprint $table) {
            $table->decimal('points_earned', 8, 2)->default(0)->nullable(false)->change();
            $table->boolean('is_correct')->nullable(false)->change();
        });
    }
};
