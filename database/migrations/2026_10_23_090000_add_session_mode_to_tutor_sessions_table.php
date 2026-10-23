<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->string('session_mode', 20)->default('learning')->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropIndex(['session_mode']);
            $table->dropColumn('session_mode');
        });
    }
};
