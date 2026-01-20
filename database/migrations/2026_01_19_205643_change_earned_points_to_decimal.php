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
        Schema::table('student_answers', function (Blueprint $table) {
            Schema::table('student_answers', function (Blueprint $table) {
            $table->decimal('points_earned', 8, 2)->default(0)->change();
        });

        // If student_assessments.score is also integer, change it too (recommended)
        Schema::table('student_assessments', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->default(0)->change();
            $table->decimal('max_score', 8, 2)->default(0)->change();
        });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_answers', function (Blueprint $table) {
            $table->integer('points_earned')->default(0)->change();
        });

        Schema::table('student_assessments', function (Blueprint $table) {
            $table->integer('score')->default(0)->change();
            $table->integer('max_score')->default(0)->change();
        });
    }
};
