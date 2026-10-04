<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tutor_session_objectives MODIFY status ENUM('not_started','in_progress','completed','needs_review') NOT NULL DEFAULT 'not_started'");
    }

    public function down(): void
    {
        DB::table('tutor_session_objectives')
            ->where('status', 'needs_review')
            ->update(['status' => 'in_progress']);

        DB::statement("ALTER TABLE tutor_session_objectives MODIFY status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started'");
    }
};
