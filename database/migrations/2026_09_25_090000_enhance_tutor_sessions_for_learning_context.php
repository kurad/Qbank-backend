<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('student_id')->constrained('groups')->nullOnDelete();
            $table->foreignId('grade_subject_id')->nullable()->after('group_id')->constrained('grade_subjects')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->after('grade_subject_id')->constrained('subjects')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->after('subject_id')->constrained('units')->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->after('unit_id')->constrained('topics')->nullOnDelete();
            $table->foreignId('learning_objective_id')->nullable()->after('topic_id')->constrained('learning_objectives')->nullOnDelete();
            $table->enum('status', ['active', 'completed', 'abandoned'])->default('active')->after('title');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('ended_at')->nullable()->after('last_activity_at');

            $table->index(['student_id', 'group_id']);
            $table->index(['student_id', 'subject_id', 'topic_id']);
            $table->index(['topic_id', 'learning_objective_id']);
        });

        Schema::table('tutor_messages', function (Blueprint $table) {
            $table->string('message_type')->default('text')->after('role');
            $table->json('metadata')->after('content');
            $table->index(['tutor_session_id', 'created_at']);
        });

        // New tutor sessions no longer require a course material. Existing rows
        // remain linked to their material where one already exists.
        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropForeign(['course_material_id']);
            $table->foreignId('course_material_id')->nullable()->change();
            $table->foreign('course_material_id')->references('id')->on('course_materials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table) {
            $table->dropIndex(['tutor_session_id', 'created_at']);
            $table->dropColumn('message_type');
        });

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropForeign(['course_material_id']);
            $table->foreignId('course_material_id')->nullable(false)->change();
            $table->foreign('course_material_id')->references('id')->on('course_materials')->cascadeOnDelete();

            $table->dropForeign(['group_id']);
            $table->dropForeign(['grade_subject_id']);
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['topic_id']);
            $table->dropForeign(['learning_objective_id']);

            $table->dropIndex(['student_id', 'group_id']);
            $table->dropIndex(['student_id', 'subject_id', 'topic_id']);
            $table->dropIndex(['topic_id', 'learning_objective_id']);

            $table->dropColumn([
                'group_id', 'grade_subject_id', 'subject_id', 'unit_id',
                'topic_id', 'learning_objective_id', 'status', 'started_at', 'ended_at'
            ]);
        });
    }
};
