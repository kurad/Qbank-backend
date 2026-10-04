<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE course_materials
            MODIFY extraction_status
            ENUM('pending', 'processing', 'ready', 'failed')
            NOT NULL
            DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE course_materials
            MODIFY extraction_status
            ENUM('pending', 'ready', 'failed')
            NOT NULL
            DEFAULT 'pending'
        ");
    }
};