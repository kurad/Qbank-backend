<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('questions', 'question_fingerprint')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->string('question_fingerprint', 64)
                    ->nullable()
                    ->after('question');

                $table->index(
                    'question_fingerprint',
                    'questions_question_fingerprint_index'
                );
            });
        }

        /*
         * Backfill fingerprints for existing questions.
         *
         * The fingerprint deliberately includes topic, learning objective,
         * question type and normalized question text. Difficulty is excluded
         * because the same question should not become a different question
         * merely because its difficulty metadata differs.
         */
        DB::table('questions')
            ->orderBy('id')
            ->chunkById(500, function ($questions) {
                foreach ($questions as $question) {
                    $fingerprint = $this->fingerprint(
                        $question->topic_id,
                        $question->learning_objective_id,
                        $question->question_type,
                        $question->question
                    );

                    DB::table('questions')
                        ->where('id', $question->id)
                        ->update([
                            'question_fingerprint' => $fingerprint,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('questions', 'question_fingerprint')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropIndex('questions_question_fingerprint_index');
                $table->dropColumn('question_fingerprint');
            });
        }
    }

    private function fingerprint(
        $topicId,
        $learningObjectiveId,
        $questionType,
        $questionText
    ): string {
        $normalized = $this->normalizeText($questionText);

        $key = implode('|', [
            (int) $topicId,
            (int) ($learningObjectiveId ?? 0),
            mb_strtolower(trim((string) $questionType), 'UTF-8'),
            $normalized,
        ]);

        return hash('sha256', $key);
    }

    private function normalizeText($value): string
    {
        $value = strip_tags((string) $value);
        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $value = str_replace(
            ['“', '”', '‘', '’', '–', '—', '…'],
            ['"', '"', "'", "'", '-', '-', '...'],
            $value
        );

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));

        return $value;
    }
};
