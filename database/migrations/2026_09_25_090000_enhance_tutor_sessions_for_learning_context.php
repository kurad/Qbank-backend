<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop any foreign key currently attached to a specific column.
     */
    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $database = DB::connection()->getDatabaseName();

        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$database, $table, $column]);

        foreach ($foreignKeys as $foreignKey) {
            $constraint = $foreignKey->CONSTRAINT_NAME;

            DB::statement(
                "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`"
            );
        }
    }

    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Tutor sessions
        |--------------------------------------------------------------------------
        */

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')
                ->nullable()
                ->after('student_id');

            $table->unsignedBigInteger('grade_subject_id')
                ->nullable()
                ->after('group_id');

            $table->unsignedBigInteger('subject_id')
                ->nullable()
                ->after('grade_subject_id');

            $table->unsignedBigInteger('unit_id')
                ->nullable()
                ->after('subject_id');

            $table->unsignedBigInteger('topic_id')
                ->nullable()
                ->after('unit_id');

            $table->unsignedBigInteger('learning_objective_id')
                ->nullable()
                ->after('topic_id');

            $table->enum('status', [
                'active',
                'completed',
                'abandoned'
            ])
                ->default('active')
                ->after('title');

            $table->timestamp('started_at')
                ->nullable()
                ->after('status');

            $table->timestamp('ended_at')
                ->nullable()
                ->after('last_activity_at');
        });

        /*
        |--------------------------------------------------------------------------
        | Foreign keys
        |--------------------------------------------------------------------------
        |
        | Create these separately. This makes failures much easier to diagnose.
        |
        */

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->foreign('group_id', 'ts_group_fk')
                ->references('id')
                ->on('groups')
                ->nullOnDelete();

            $table->foreign('grade_subject_id', 'ts_grade_subject_fk')
                ->references('id')
                ->on('grade_subjects')
                ->nullOnDelete();

            $table->foreign('subject_id', 'ts_subject_fk')
                ->references('id')
                ->on('subjects')
                ->nullOnDelete();

            $table->foreign('unit_id', 'ts_unit_fk')
                ->references('id')
                ->on('units')
                ->nullOnDelete();

            $table->foreign('topic_id', 'ts_topic_fk')
                ->references('id')
                ->on('topics')
                ->nullOnDelete();

            $table->foreign(
                'learning_objective_id',
                'ts_learning_objective_fk'
            )
                ->references('id')
                ->on('learning_objectives')
                ->nullOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Indexes
        |--------------------------------------------------------------------------
        */

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->index(
                ['student_id', 'group_id'],
                'ts_student_group_idx'
            );

            $table->index(
                ['student_id', 'subject_id', 'topic_id'],
                'ts_student_subject_topic_idx'
            );

            $table->index(
                ['topic_id', 'learning_objective_id'],
                'ts_topic_objective_idx'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Tutor messages
        |--------------------------------------------------------------------------
        */

        Schema::table('tutor_messages', function (Blueprint $table) {
            $table->string('message_type', 50)
                ->default('text')
                ->after('role');

            $table->json('metadata')
                ->nullable()
                ->after('content');

            $table->index(
                ['tutor_session_id', 'created_at'],
                'tm_session_created_idx'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Course material becomes optional
        |--------------------------------------------------------------------------
        */

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'course_material_id'
        );

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('course_material_id')
                ->nullable()
                ->change();
        });

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->foreign(
                'course_material_id',
                'ts_course_material_fk'
            )
                ->references('id')
                ->on('course_materials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Tutor messages
        |--------------------------------------------------------------------------
        */

        Schema::table('tutor_messages', function (Blueprint $table) {
            $table->dropIndex('tm_session_created_idx');

            $table->dropColumn([
                'message_type',
                'metadata',
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | Course material
        |--------------------------------------------------------------------------
        */

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'course_material_id'
        );

        /*
        | Do not force NOT NULL here because existing V2 sessions may already
        | contain NULL course_material_id values.
        */

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->foreign(
                'course_material_id',
                'ts_course_material_fk'
            )
                ->references('id')
                ->on('course_materials')
                ->cascadeOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | New tutor session relationships
        |--------------------------------------------------------------------------
        */

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'group_id'
        );

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'grade_subject_id'
        );

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'subject_id'
        );

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'unit_id'
        );

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'topic_id'
        );

        $this->dropForeignKeyIfExists(
            'tutor_sessions',
            'learning_objective_id'
        );

        Schema::table('tutor_sessions', function (Blueprint $table) {
            $table->dropIndex('ts_student_group_idx');
            $table->dropIndex('ts_student_subject_topic_idx');
            $table->dropIndex('ts_topic_objective_idx');

            $table->dropColumn([
                'group_id',
                'grade_subject_id',
                'subject_id',
                'unit_id',
                'topic_id',
                'learning_objective_id',
                'status',
                'started_at',
                'ended_at',
            ]);
        });
    }
};