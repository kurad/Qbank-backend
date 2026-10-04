<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_analyses', function (Blueprint $table) {
            $table->text('instruction')
                ->nullable()
                ->after('version');

            $table->foreignId('parent_analysis_id')
                ->nullable()
                ->after('instruction')
                ->constrained('curriculum_analyses')
                ->nullOnDelete();

            $table->index('parent_analysis_id');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_analyses', function (Blueprint $table) {
            $table->dropForeign(['parent_analysis_id']);
            $table->dropIndex(['parent_analysis_id']);
            $table->dropColumn([
                'instruction',
                'parent_analysis_id',
            ]);
        });
    }
};