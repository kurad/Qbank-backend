<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->string('visual_extraction_status', 30)
                ->default('pending')
                ->after('extraction_status');

            $table->timestamp('visual_extracted_at')
                ->nullable()
                ->after('extracted_at');

            $table->text('visual_extraction_error')
                ->nullable()
                ->after('extraction_error');
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropColumn([
                'visual_extraction_status',
                'visual_extracted_at',
                'visual_extraction_error',
            ]);
        });
    }
};