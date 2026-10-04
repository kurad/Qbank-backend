<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_id')
                ->constrained('groups')
                ->cascadeOnDelete();

            $table->foreignId('grade_subject_id')
                ->constrained('grade_subjects')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->date('start_date');
            $table->date('end_date');

            /*
             * draft     = teacher is preparing it
             * published = visible to students according to dates
             * archived  = no longer used
             */
            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(
                ['group_id', 'grade_subject_id', 'start_date', 'end_date'],
                'lp_group_subject_dates_idx'
            );

            $table->index(
                ['group_id', 'status'],
                'lp_group_status_idx'
            );
        });

        Schema::create('learning_period_topics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('learning_period_id')
                ->constrained('learning_periods')
                ->cascadeOnDelete();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->cascadeOnDelete();

            $table->foreignId('topic_id')
                ->constrained('topics')
                ->cascadeOnDelete();

            $table->unsignedInteger('display_order')->default(1);

            $table->timestamps();
            $table->unique(
                ['learning_period_id', 'topic_id'],
                'lp_topic_unique'
            );

            $table->index(
                ['learning_period_id', 'display_order'],
                'lp_topic_order_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_period_topics');
        Schema::dropIfExists('learning_periods');
    }
};