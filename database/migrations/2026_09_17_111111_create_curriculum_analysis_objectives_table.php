<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('curriculum_analysis_objectives')) {
            Schema::create('curriculum_analysis_objectives', function (Blueprint $table) {
                $table->id();

                $table->foreignId('curriculum_analysis_topic_id');

                $table->string('code')->nullable();

                $table->text('objective');

                $table->text('description')->nullable();

                $table->unsignedInteger('order')->default(0);

                $table->enum('status', [
                    'proposed',
                    'approved',
                    'rejected',
                ])->default('proposed');

                $table->timestamps();

                $table->index(
                    ['curriculum_analysis_topic_id', 'order'],
                    'cao_topic_order_idx'
                );

                $table->foreign(
                    'curriculum_analysis_topic_id',
                    'cao_topic_fk'
                )
                    ->references('id')
                    ->on('curriculum_analysis_topics')
                    ->cascadeOnDelete();
            });

            return;
        }

        /*
         * The table was created before the previous migration
         * failed while creating the foreign key.
         *
         * Add the missing index and foreign key.
         */
        Schema::table('curriculum_analysis_objectives', function (Blueprint $table) {
            $table->index(
                ['curriculum_analysis_topic_id', 'order'],
                'cao_topic_order_idx'
            );

            $table->foreign(
                'curriculum_analysis_topic_id',
                'cao_topic_fk'
            )
                ->references('id')
                ->on('curriculum_analysis_topics')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_analysis_objectives');
    }
};
