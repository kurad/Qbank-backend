<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->enum('scope', ['unit','topic',])->default('topic')->after('topic_id');
            $table->unsignedInteger('version')->default(1)->after('scope');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->index(['teacher_id','status',]);

            $table->index([
                'unit_id',
                'status',
            ]);

            $table->index([
                'topic_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('course_materials', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);

            $table->dropIndex([
                'teacher_id',
                'status',
            ]);

            $table->dropIndex([
                'unit_id',
                'status',
            ]);

            $table->dropIndex([
                'topic_id',
                'status',
            ]);

            $table->dropColumn([
                'scope',
                'version',
                'approved_by',
                'approved_at',
            ]);
        });
    }
};