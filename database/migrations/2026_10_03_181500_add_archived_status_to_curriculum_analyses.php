<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE curriculum_analyses MODIFY COLUMN status " .
            "ENUM('pending','processing','ready','approved','failed','archived') " .
            "NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        /*
         * Preserve rollback safety by moving archived analyses back to
         * approved before shrinking the ENUM.
         */
        DB::table('curriculum_analyses')
            ->where('status', 'archived')
            ->update(['status' => 'approved']);

        DB::statement(
            "ALTER TABLE curriculum_analyses MODIFY COLUMN status " .
            "ENUM('pending','processing','ready','approved','failed') " .
            "NOT NULL DEFAULT 'pending'"
        );
    }
};
