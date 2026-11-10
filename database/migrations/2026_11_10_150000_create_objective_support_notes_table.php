
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL may leave the table behind if creating a later index fails.
        // Keep the existing table and its records on a retry.
        if (!Schema::hasTable('objective_support_notes')) {
            Schema::create('objective_support_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('learning_objective_id')->constrained()->cascadeOnDelete();
                $table->foreignId('grade_subject_id')->constrained()->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('users');
                $table->longText('content');
                $table->timestamps();
            });
        }

        foreach ([
            'id',
            'learning_objective_id',
            'grade_subject_id',
            'teacher_id',
            'content',
            'created_at',
            'updated_at'
        ] as $column) {
            if (!Schema::hasColumn('objective_support_notes', $column)) {
                throw new \RuntimeException(
                    "Existing objective_support_notes table is missing {$column}; inspect it before retrying migration."
                );
            }
        }

        // Explicit name stays under MySQL's 64-character identifier limit.
        $indexName = 'osn_objective_grade_unique';

        $indexes = DB::select('SHOW INDEX FROM `objective_support_notes`');

        $indexExists = collect($indexes)->contains(function ($index) use ($indexName) {
            return ($index->Key_name ?? null) === $indexName;
        });

        if (!$indexExists) {
            Schema::table('objective_support_notes', function (Blueprint $table) use ($indexName) {
                $table->unique(
                    ['learning_objective_id', 'grade_subject_id'],
                    $indexName
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('objective_support_notes');
    }
};
