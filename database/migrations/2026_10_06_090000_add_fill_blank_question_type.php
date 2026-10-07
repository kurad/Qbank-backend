<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE questions MODIFY question_type ENUM('mcq','true_false','short_answer','matching','parent','open_ended','fill_blank') NOT NULL DEFAULT 'mcq'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE questions MODIFY question_type ENUM('mcq','true_false','short_answer','matching','parent','open_ended') NOT NULL DEFAULT 'mcq'");
    }
};
