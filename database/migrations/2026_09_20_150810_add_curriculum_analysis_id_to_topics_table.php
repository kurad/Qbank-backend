<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->foreignId('curriculum_analysis_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('curriculum_analyses')
                ->nullOnDelete();

            $table->index('curriculum_analysis_id');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropForeign(['curriculum_analysis_id']);
            $table->dropIndex(['curriculum_analysis_id']);
            $table->dropColumn('curriculum_analysis_id');
        });
    }
};