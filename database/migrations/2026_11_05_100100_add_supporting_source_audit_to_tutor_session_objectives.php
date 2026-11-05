<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_session_objectives', function (Blueprint $table) {
            if (!Schema::hasColumn('tutor_session_objectives', 'supporting_source_ids')) {
                $table->json('supporting_source_ids')
                    ->nullable()
                    ->after('support_reason');
            }

            if (!Schema::hasColumn('tutor_session_objectives', 'supporting_source_started_at')) {
                $table->timestamp('supporting_source_started_at')
                    ->nullable()
                    ->after('supporting_source_ids');
            }

            if (!Schema::hasColumn('tutor_session_objectives', 'supporting_source_mastered_at')) {
                $table->timestamp('supporting_source_mastered_at')
                    ->nullable()
                    ->after('supporting_source_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tutor_session_objectives', function (Blueprint $table) {
            $columns = [];

            foreach ([
                'supporting_source_ids',
                'supporting_source_started_at',
                'supporting_source_mastered_at',
            ] as $column) {
                if (Schema::hasColumn('tutor_session_objectives', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
