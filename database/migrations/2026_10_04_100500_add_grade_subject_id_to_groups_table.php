<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('grade_subject_id')
                ->nullable()
                ->after('created_by')
                ->constrained('grade_subjects')
                ->nullOnDelete();

            $table->index(['created_by', 'grade_subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['grade_subject_id']);
            $table->dropIndex(['created_by', 'grade_subject_id']);
            $table->dropColumn('grade_subject_id');
        });
    }
};
