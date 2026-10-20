<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'admin_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('admin_scope', 20)->nullable()->after('role')->index('users_admin_scope_idx');
            });
        }

        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('admin_scope')
            ->whereNull('school_id')
            ->update(['admin_scope' => 'system']);

        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('admin_scope')
            ->whereNotNull('school_id')
            ->update(['admin_scope' => 'school']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'admin_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_admin_scope_idx');
                $table->dropColumn('admin_scope');
            });
        }
    }
};
