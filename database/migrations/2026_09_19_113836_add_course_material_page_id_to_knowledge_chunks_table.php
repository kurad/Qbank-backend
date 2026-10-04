<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->foreignId('course_material_page_id')
                ->nullable()
                ->after('course_material_id')
                ->constrained('course_material_pages')
                ->nullOnDelete();

            $table->index(
                ['course_material_id', 'course_material_page_id'],
                'knowledge_chunks_material_page_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropForeign([
                'course_material_page_id',
            ]);

            $table->dropIndex(
                'knowledge_chunks_material_page_index'
            );

            $table->dropColumn('course_material_page_id');
        });
    }
};