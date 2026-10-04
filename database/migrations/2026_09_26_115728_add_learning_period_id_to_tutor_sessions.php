<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->foreignId('learning_period_id')
                ->nullable()
                ->after('group_id')
                ->constrained('learning_periods')
                ->nullOnDelete();

            $table->index(
                ['learning_period_id', 'student_id'],
                'tutor_session_period_student_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropForeign([
                'learning_period_id',
            ]);

            $table->dropIndex(
                'tutor_session_period_student_idx'
            );

            $table->dropColumn('learning_period_id');
        });
    }
};