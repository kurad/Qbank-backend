<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->foreignId('school_id')
                ->nullable()
                ->after('id')
                ->constrained('schools')
                ->nullOnDelete();

            $table->foreignId('teacher_id')
                ->nullable()
                ->after('school_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['school_id', 'teacher_id']);
            $table->index([
                'teacher_id',
                'grade_level_id',
                'subject_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['teacher_id']);
            $table->dropIndex(['school_id', 'teacher_id']);
            $table->dropIndex([
                'teacher_id',
                'grade_level_id',
                'subject_id'
            ]);
            $table->dropColumn([
                'school_id',
                'teacher_id'
            ]);
        });
    }
};