<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->index(['school_id', 'grade_level_id', 'subject_id'], 'gs_school_grade_subject_index');
            $table->index(['school_id', 'teacher_id'], 'gs_school_teacher_index');
        });
    }

    public function down(): void
    {
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->dropIndex('gs_school_grade_subject_index');
            $table->dropIndex('gs_school_teacher_index');
        });
    }
};
