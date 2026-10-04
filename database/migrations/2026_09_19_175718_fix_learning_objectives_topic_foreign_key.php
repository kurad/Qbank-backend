<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $databaseName = DB::connection()->getDatabaseName();

        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = 'learning_objectives'
              AND COLUMN_NAME = 'topic_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$databaseName]);

        foreach ($foreignKeys as $foreignKey) {
            DB::statement(
                "ALTER TABLE `learning_objectives`
                 DROP FOREIGN KEY `{$foreignKey->CONSTRAINT_NAME}`"
            );
        }

        Schema::table('learning_objectives', function (Blueprint $table) {
            $table->foreign('topic_id')
                ->references('id')
                ->on('topics')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $databaseName = DB::connection()->getDatabaseName();

        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = 'learning_objectives'
              AND COLUMN_NAME = 'topic_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$databaseName]);

        foreach ($foreignKeys as $foreignKey) {
            DB::statement(
                "ALTER TABLE `learning_objectives`
                 DROP FOREIGN KEY `{$foreignKey->CONSTRAINT_NAME}`"
            );
        }

        Schema::table('learning_objectives', function (Blueprint $table) {
            $table->foreign('topic_id')
                ->references('id')
                ->on('legacy_units')
                ->cascadeOnDelete();
        });
    }
};