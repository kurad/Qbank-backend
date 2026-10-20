<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('teacher_approval_status', ['pending', 'approved', 'rejected'])
                ->nullable()
                ->after('status');
            $table->timestamp('teacher_approved_at')->nullable()->after('teacher_approval_status');
            $table->foreignId('teacher_approved_by')->nullable()->after('teacher_approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_approved_by');
            $table->dropColumn(['teacher_approval_status', 'teacher_approved_at']);
        });
    }
};
