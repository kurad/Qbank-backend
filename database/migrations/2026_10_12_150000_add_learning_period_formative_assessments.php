<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RevisionHub production uses MySQL/MariaDB. Extend the existing enum
        // rather than replacing the assessment table.
        DB::statement(
            "ALTER TABLE assessments MODIFY type ENUM('quiz','exam','homework','practice','formative') NOT NULL"
        );

        Schema::table('assessments', function (Blueprint $table) {
            $table
                ->foreignId('learning_period_id')
                ->nullable()
                ->after('creator_id')
                ->constrained('learning_periods')
                ->nullOnDelete();

            $table
                ->boolean('is_system_generated')
                ->default(false)
                ->after('learning_period_id');

            $table
                ->boolean('is_mandatory')
                ->default(false)
                ->after('is_system_generated');

            $table
                ->string('purpose', 60)
                ->nullable()
                ->after('is_mandatory');

            $table->unique(
                ['learning_period_id', 'purpose'],
                'assessment_period_purpose_unique'
            );
        });
    }

    public function down(): void
    {
        // Preserve rollback safety if formative rows already exist.
        DB::table('assessments')
            ->where('type', 'formative')
            ->update(['type' => 'quiz']);

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropUnique('assessment_period_purpose_unique');
            $table->dropForeign(['learning_period_id']);
            $table->dropColumn([
                'learning_period_id',
                'is_system_generated',
                'is_mandatory',
                'purpose',
            ]);
        });

        DB::statement(
            "ALTER TABLE assessments MODIFY type ENUM('quiz','exam','homework','practice') NOT NULL"
        );
    }
};
