<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->string('processing_status', 30)->default('queued')->after('status');
            $table->string('processing_stage', 30)->nullable()->after('processing_status');
            $table->unsignedTinyInteger('processing_progress')->default(0)->after('processing_stage');
            $table->text('processing_error')->nullable()->after('processing_progress');
            $table->timestamp('processing_started_at')->nullable()->after('processing_error');
            $table->timestamp('processing_completed_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropColumn([
                'processing_status',
                'processing_stage',
                'processing_progress',
                'processing_error',
                'processing_started_at',
                'processing_completed_at',
            ]);
        });
    }
};