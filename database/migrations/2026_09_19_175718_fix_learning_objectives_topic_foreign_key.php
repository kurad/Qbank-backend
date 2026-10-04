<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_objectives', function (Blueprint $table) {
            $table->dropForeign('learning_objectives_topic_id_foreign');
        });

        Schema::table('learning_objectives', function (Blueprint $table) {
            $table->foreign('topic_id')
                ->references('id')
                ->on('topics')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('learning_objectives', function (Blueprint $table) {
            $table->dropForeign('learning_objectives_topic_id_foreign');
        });

        Schema::table('learning_objectives', function (Blueprint $table) {
            $table->foreign('topic_id')
                ->references('id')
                ->on('legacy_units')
                ->cascadeOnDelete();
        });
    }
};