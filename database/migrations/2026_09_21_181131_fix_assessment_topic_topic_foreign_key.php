<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The old foreign key may already have been removed manually
         * or by a previous migration. Therefore, do not blindly call
         * dropForeign().
         */

        // Check whether the old FK actually exists.
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'assessment_topic'
              AND COLUMN_NAME = 'topic_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $foreignKey) {
            Schema::table('assessment_topic', function (Blueprint $table) use ($foreignKey) {
                $table->dropForeign($foreignKey->CONSTRAINT_NAME);
            });
        }

        /*
         * Make sure all existing topic_id values belong to the
         * new topics table before creating the FK.
         *
         * Invalid historical rows must be cleaned first.
         */

        Schema::table('assessment_topic', function (Blueprint $table) {
            $table->foreign('topic_id', 'assessment_topic_topic_fk')
                ->references('id')
                ->on('topics')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_topic', function (Blueprint $table) {
            $table->dropForeign('assessment_topic_topic_fk');
        });
    }
};