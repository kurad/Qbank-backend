<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('school_id')
                ->nullable()
                ->after('id')
                ->constrained('schools')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->after('school_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['school_id', 'name']);
            $table->index(['school_id', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['created_by']);
            $table->dropIndex(['school_id', 'name']);
            $table->dropIndex(['school_id', 'created_by']);
            $table->dropColumn(['school_id', 'created_by']);
        });
    }
};