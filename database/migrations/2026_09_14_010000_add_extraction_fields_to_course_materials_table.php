<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->longText('extracted_text')->nullable()->after('file_type');
            $table->enum('extraction_status', ['pending', 'ready', 'failed'])
                ->default('pending')
                ->after('extracted_text');
            $table->timestamp('extracted_at')->nullable()->after('extraction_status');
            $table->text('extraction_error')->nullable()->after('extracted_at');
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropColumn([
                'extracted_text',
                'extraction_status',
                'extracted_at',
                'extraction_error',
            ]);
        });
    }
};
