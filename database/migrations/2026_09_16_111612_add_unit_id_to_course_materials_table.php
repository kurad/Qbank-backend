<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('subject_id')->constrained('units')->nullOnDelete();
            $table->index([
                'subject_id',
                'unit_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['subject_id', 'unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};