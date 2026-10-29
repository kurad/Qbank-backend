<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_session_objectives', function (Blueprint $table) {
            $table->string('support_status', 20)
                ->default('unknown')
                ->after('notes');
            $table->text('support_reason')
                ->nullable()
                ->after('support_status');

            $table->index(
                ['tutor_session_id', 'support_status'],
                'tso_session_support_status_index'
            );
        });

        DB::statement(
            "ALTER TABLE tutor_session_objectives
             MODIFY status ENUM(
                'not_started',
                'in_progress',
                'completed',
                'needs_review',
                'material_gap'
             ) NOT NULL DEFAULT 'not_started'"
        );
    }

    public function down(): void
    {
        DB::table('tutor_session_objectives')
            ->where('status', 'material_gap')
            ->update(['status' => 'not_started']);

        DB::statement(
            "ALTER TABLE tutor_session_objectives
             MODIFY status ENUM(
                'not_started',
                'in_progress',
                'completed',
                'needs_review'
             ) NOT NULL DEFAULT 'not_started'"
        );

        Schema::table('tutor_session_objectives', function (Blueprint $table) {
            $table->dropIndex('tso_session_support_status_index');
            $table->dropColumn([
                'support_status',
                'support_reason',
            ]);
        });
    }
};
