<?php

namespace App\Services;

use App\Models\GradeSubject;
use App\Models\LearningObjective;
use App\Models\Question;
use App\Models\QuestionnaireImport;
use App\Models\QuestionnaireImportItem;
use App\Models\Topic;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class QuestionnaireImportService
{
    public function __construct(
        protected AIGateway $ai,
        protected QuestionnaireVisionService $vision
    ) {
    }

    public function process(QuestionnaireImport $import): void
    {
        $import->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $gradeSubject = GradeSubject::with([
                'subject:id,name',
                'gradeLevel:id,name',
                'units.topics.learningObjectives',
            ])->findOrFail($import->grade_subject_id);

            $absolutePath = Storage::disk('local')
                ->path($import->file_path);

            $mimeType = strtolower((string) $import->mime_type);

            if ($this->isImage($mimeType, $import->original_name)) {
                $payloads = [
                    $this->extractFromImage(
                        $absolutePath,
                        $mimeType,
                        $gradeSubject
                    ),
                ];

                $import->update([
                    'extracted_text' => null,
                ]);
            } else {
                $text = $this->extractText(
                    $absolutePath,
                    $import->original_name,
                    $mimeType
                );

                if (mb_strlen(trim($text)) < 20) {
                    throw new RuntimeException(
                        'Very little text could be extracted from this questionnaire.'
                    );
                }

                $import->update([
                    'extracted_text' => mb_substr($text, 0, 200000),
                ]);

                $payloads = [];

                foreach ($this->chunkText($text) as $chunk) {
                    $payloads[] = $this->extractFromText(
                        $chunk,
                        $gradeSubject
                    );
                }
            }

            $questions = [];

            foreach ($payloads as $payload) {
                foreach (($payload['questions'] ?? []) as $question) {
                    if (is_array($question)) {
                        $questions[] = $question;
                    }
                }
            }

            if (!$questions) {
                throw new RuntimeException(
                    'No questions were identified in this questionnaire.'
                );
            }

            DB::transaction(function () use (
                $import,
                $gradeSubject,
                $questions
            ) {
                $import->items()->delete();

                foreach ($questions as $index => $raw) {
                    $normalized = $this->normalizeItem(
                        $raw,
                        $gradeSubject
                    );

                    if (!$normalized['question']) {
                        continue;
                    }

                    QuestionnaireImportItem::create([
                        'questionnaire_import_id' => $import->id,
                        'source_order' => $index + 1,
                        'source_reference' =>
                            $normalized['source_reference'],
                        'question_type' =>
                            $normalized['question_type'],
                        'question' =>
                            $normalized['question'],
                        'options' =>
                            $normalized['options'],
                        'correct_answer' =>
                            $normalized['correct_answer'],
                        'marks' =>
                            $normalized['marks'],
                        'difficulty_level' =>
                            $normalized['difficulty_level'],
                        'is_math' =>
                            $normalized['is_math'],
                        'is_chemistry' =>
                            $normalized['is_chemistry'],
                        'explanation' =>
                            $normalized['explanation'],
                        'unit_id' =>
                            $normalized['unit_id'],
                        'topic_id' =>
                            $normalized['topic_id'],
                        'learning_objective_id' =>
                            $normalized['learning_objective_id'],
                        'mapping_confidence' =>
                            $normalized['mapping_confidence'],
                        'duplicate_question_id' =>
                            $normalized['duplicate_question_id'],
                        'status' => 'pending',
                    ]);
                }

                $count = $import->items()->count();

                $import->update([
                    'status' => 'review',
                    'question_count' => $count,
                ]);
            });
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function extractText(
        string $absolutePath,
        string $originalName,
        string $mimeType
    ): string {
        $extension = strtolower(
            pathinfo($originalName, PATHINFO_EXTENSION)
        );

        if ($extension === 'txt') {
            return (string) file_get_contents($absolutePath);
        }

        if ($extension === 'docx') {
            return $this->extractDocx($absolutePath);
        }

        if ($extension === 'pdf' || str_contains($mimeType, 'pdf')) {
            return $this->extractPdf($absolutePath);
        }

        throw new RuntimeException(
            'Unsupported questionnaire file type.'
        );
    }

    protected function extractPdf(string $absolutePath): string
    {
        $binary = config(
            'services.questionnaire.pdftotext_binary',
            '/usr/bin/pdftotext'
        );

        if (!is_file($binary) && !is_executable($binary)) {
            $binary = trim(
                (string) shell_exec('command -v pdftotext 2>/dev/null')
            );
        }

        if (!$binary) {
            throw new RuntimeException(
                'pdftotext is not installed on the server.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'revisionhub_questionnaire_');

        if ($tmp === false) {
            throw new RuntimeException(
                'Unable to create temporary PDF extraction file.'
            );
        }

        $command = sprintf(
            '%s -layout %s %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($absolutePath),
            escapeshellarg($tmp)
        );

        exec($command, $output, $code);

        $text = is_file($tmp)
            ? (string) file_get_contents($tmp)
            : '';

        @unlink($tmp);

        if ($code !== 0 && trim($text) === '') {
            throw new RuntimeException(
                'PDF text extraction failed: ' .
                trim(implode("\n", $output))
            );
        }

        return $text;
    }

    protected function extractDocx(string $absolutePath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException(
                'Unable to open DOCX questionnaire.'
            );
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) {
            throw new RuntimeException(
                'DOCX does not contain readable document text.'
            );
        }

        $xml = str_replace(
            ['</w:p>', '</w:tr>', '<w:tab/>'],
            ["\n", "\n", "\t"],
            $xml
        );

        $text = strip_tags($xml);
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );

        return preg_replace(
            "/[ \t]+\n/u",
            "\n",
            trim($text)
        );
    }

    protected function extractFromText(
        string $text,
        GradeSubject $gradeSubject
    ): array {
        $curriculum = $this->curriculumContext(
            $gradeSubject
        );

        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' =>
                    "Teaching area curriculum:\n{$curriculum}\n\n" .
                    "Extract every questionnaire item found in the text below. " .
                    "Preserve the wording. Do not create extra questions. " .
                    "Map each question only to IDs explicitly present in the curriculum context.\n\n" .
                    "QUESTIONNAIRE TEXT:\n{$text}",
            ],
        ];

        return $this->ai->json(
            $messages,
            $this->responseSchema(),
            [
                'temperature' => 0.1,
                'max_tokens' => 12000,
            ]
        );
    }

    protected function extractFromImage(
        string $absolutePath,
        string $mimeType,
        GradeSubject $gradeSubject
    ): array {
        $curriculum = $this->curriculumContext(
            $gradeSubject
        );

        $prompt =
            $this->systemPrompt() .
            "\n\nTeaching area curriculum:\n{$curriculum}\n\n" .
            "Read the uploaded questionnaire image carefully. " .
            "Extract only questions that are visibly present. " .
            "Preserve wording, answer choices, marks and answers where visible. " .
            "Do not invent hidden or unreadable content. " .
            "Map each question only to curriculum IDs listed above.";

        return $this->vision->extractImageAsJson(
            $absolutePath,
            $mimeType,
            $prompt,
            $this->responseSchema()
        );
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are RevisionHub's questionnaire-import assistant.

Your job is extraction and curriculum mapping, not question generation.

Rules:
1. Extract only questions present in the supplied questionnaire.
2. Preserve the original question wording as closely as possible.
3. Detect type as one of:
   mcq, true_false, short_answer, fill_blank, matching, open_ended.
4. Preserve visible marks. If marks are not provided, return null.
5. For MCQ, options is a simple ordered string array.
6. For True/False, options should be ["True","False"].
7. For Matching, options must be:
   {"left":["..."],"right":["..."]}
   and correct_answer must be:
   [{"left_index":0,"right_index":1}, ...]
   only if the source provides enough information to infer the matching key.
8. correct_answer is always an array. If no answer/key is provided in the source,
   return an empty array rather than inventing an answer.
9. difficulty_level is the closest Bloom level:
   remembering, understanding, applying, analyzing, evaluating, creating.
10. Map to unit_id, topic_id and learning_objective_id using only IDs in the supplied curriculum.
11. mapping_confidence is a number from 0 to 100.
12. If objective mapping is uncertain, learning_objective_id may be null.
13. If topic mapping is uncertain, topic_id may be null and confidence should be low.
14. source_reference may contain a visible question number or page hint, otherwise null.
15. Do not add explanations unless the questionnaire itself includes one.
PROMPT;
    }

    protected function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'questions' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'source_reference' => [
                                'type' => 'STRING',
                                'nullable' => true,
                            ],
                            'question_type' => [
                                'type' => 'STRING',
                            ],
                            'question' => [
                                'type' => 'STRING',
                            ],
                            'options' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'STRING',
                                ],
                                'nullable' => true,
                            ],
                            'matching_left' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'STRING',
                                ],
                                'nullable' => true,
                            ],
                            'matching_right' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'STRING',
                                ],
                                'nullable' => true,
                            ],
                            'matching_pairs' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'left_index' => [
                                            'type' => 'INTEGER',
                                        ],
                                        'right_index' => [
                                            'type' => 'INTEGER',
                                        ],
                                    ],
                                    'required' => [
                                        'left_index',
                                        'right_index',
                                    ],
                                ],
                                'nullable' => true,
                            ],
                            'correct_answer' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'STRING',
                                ],
                            ],
                            'marks' => [
                                'type' => 'NUMBER',
                                'nullable' => true,
                            ],
                            'difficulty_level' => [
                                'type' => 'STRING',
                            ],
                            'is_math' => [
                                'type' => 'BOOLEAN',
                            ],
                            'is_chemistry' => [
                                'type' => 'BOOLEAN',
                            ],
                            'explanation' => [
                                'type' => 'STRING',
                                'nullable' => true,
                            ],
                            'unit_id' => [
                                'type' => 'INTEGER',
                                'nullable' => true,
                            ],
                            'topic_id' => [
                                'type' => 'INTEGER',
                                'nullable' => true,
                            ],
                            'learning_objective_id' => [
                                'type' => 'INTEGER',
                                'nullable' => true,
                            ],
                            'mapping_confidence' => [
                                'type' => 'NUMBER',
                            ],
                        ],
                        'required' => [
                            'question_type',
                            'question',
                            'correct_answer',
                            'difficulty_level',
                            'is_math',
                            'is_chemistry',
                            'mapping_confidence',
                        ],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }

    protected function curriculumContext(
        GradeSubject $gradeSubject
    ): string {
        $lines = [];

        $lines[] =
            'Teaching Area ID ' .
            $gradeSubject->id .
            ': ' .
            ($gradeSubject->gradeLevel?->name ?? 'Grade') .
            ' / ' .
            ($gradeSubject->subject?->name ?? 'Subject');

        foreach ($gradeSubject->units as $unit) {
            $lines[] =
                "UNIT {$unit->id}: {$unit->name}";

            foreach ($unit->topics as $topic) {
                $lines[] =
                    "  TOPIC {$topic->id}: {$topic->topic_name}";

                foreach ($topic->learningObjectives as $objective) {
                    $lines[] =
                        "    OBJECTIVE {$objective->id}: " .
                        trim(
                            ($objective->code
                                ? "{$objective->code} - "
                                : '') .
                            $objective->objective
                        );
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function normalizeItem(
        array $raw,
        GradeSubject $gradeSubject
    ): array {
        $allowedTypes = [
            'mcq',
            'true_false',
            'short_answer',
            'fill_blank',
            'matching',
            'open_ended',
        ];

        $allowedBloom = [
            'remembering',
            'understanding',
            'applying',
            'analyzing',
            'evaluating',
            'creating',
        ];

        $type = strtolower(
            trim((string) ($raw['question_type'] ?? 'short_answer'))
        );

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'short_answer';
        }

        $difficulty = strtolower(
            trim((string) (
                $raw['difficulty_level']
                ?? 'understanding'
            ))
        );

        if (!in_array($difficulty, $allowedBloom, true)) {
            $difficulty = 'understanding';
        }

        $unitId = $this->validUnitId(
            $gradeSubject,
            $raw['unit_id'] ?? null
        );

        $topicId = $this->validTopicId(
            $gradeSubject,
            $unitId,
            $raw['topic_id'] ?? null
        );

        $objectiveId = $this->validObjectiveId(
            $gradeSubject,
            $topicId,
            $raw['learning_objective_id'] ?? null
        );

        $options = $raw['options'] ?? null;
        $correctAnswer = $raw['correct_answer'] ?? [];

        if ($type === 'matching') {
            $options = [
                'left' => array_values(
                    array_filter(
                        (array) ($raw['matching_left'] ?? []),
                        fn ($value) => trim((string) $value) !== ''
                    )
                ),
                'right' => array_values(
                    array_filter(
                        (array) ($raw['matching_right'] ?? []),
                        fn ($value) => trim((string) $value) !== ''
                    )
                ),
            ];

            $correctAnswer = array_values(
                array_filter(
                    (array) ($raw['matching_pairs'] ?? []),
                    fn ($pair) =>
                        is_array($pair) &&
                        isset(
                            $pair['left_index'],
                            $pair['right_index']
                        )
                )
            );
        } elseif ($type === 'true_false') {
            $options = ['True', 'False'];
        } elseif ($type === 'mcq') {
            $options = array_values(
                array_filter(
                    (array) $options,
                    fn ($value) => trim((string) $value) !== ''
                )
            );
        } else {
            $options = null;
        }

        if (!is_array($correctAnswer)) {
            $correctAnswer = [$correctAnswer];
        }

        $question = trim(
            (string) ($raw['question'] ?? '')
        );

        $duplicateQuestionId = null;

        if ($topicId && $question) {
            $fingerprint = Question::buildQuestionFingerprint(
                $topicId,
                $objectiveId,
                $type,
                $question
            );

            $duplicateQuestionId = Question::query()
                ->where('question_fingerprint', $fingerprint)
                ->value('id');
        }

        return [
            'source_reference' =>
                isset($raw['source_reference'])
                    ? trim((string) $raw['source_reference'])
                    : null,
            'question_type' => $type,
            'question' => $question,
            'options' => $options,
            'correct_answer' => array_values($correctAnswer),
            'marks' =>
                is_numeric($raw['marks'] ?? null)
                    ? max(0, (float) $raw['marks'])
                    : null,
            'difficulty_level' => $difficulty,
            'is_math' => (bool) ($raw['is_math'] ?? false),
            'is_chemistry' => (bool) ($raw['is_chemistry'] ?? false),
            'explanation' =>
                isset($raw['explanation'])
                    ? trim((string) $raw['explanation'])
                    : null,
            'unit_id' => $unitId,
            'topic_id' => $topicId,
            'learning_objective_id' => $objectiveId,
            'mapping_confidence' =>
                min(
                    100,
                    max(
                        0,
                        (float) ($raw['mapping_confidence'] ?? 0)
                    )
                ),
            'duplicate_question_id' => $duplicateQuestionId,
        ];
    }

    protected function validUnitId(
        GradeSubject $gradeSubject,
        mixed $candidate
    ): ?int {
        $id = is_numeric($candidate)
            ? (int) $candidate
            : null;

        return $id &&
            $gradeSubject->units->contains('id', $id)
                ? $id
                : null;
    }

    protected function validTopicId(
        GradeSubject $gradeSubject,
        ?int $unitId,
        mixed $candidate
    ): ?int {
        $id = is_numeric($candidate)
            ? (int) $candidate
            : null;

        if (!$id) {
            return null;
        }

        foreach ($gradeSubject->units as $unit) {
            if (
                $unitId &&
                (int) $unit->id !== $unitId
            ) {
                continue;
            }

            if ($unit->topics->contains('id', $id)) {
                return $id;
            }
        }

        return null;
    }

    protected function validObjectiveId(
        GradeSubject $gradeSubject,
        ?int $topicId,
        mixed $candidate
    ): ?int {
        $id = is_numeric($candidate)
            ? (int) $candidate
            : null;

        if (!$id || !$topicId) {
            return null;
        }

        foreach ($gradeSubject->units as $unit) {
            foreach ($unit->topics as $topic) {
                if ((int) $topic->id !== $topicId) {
                    continue;
                }

                return $topic->learningObjectives
                    ->contains('id', $id)
                        ? $id
                        : null;
            }
        }

        return null;
    }

    protected function chunkText(string $text): array
    {
        $text = trim($text);

        if (mb_strlen($text) <= 18000) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length && count($chunks) < 10) {
            $chunk = mb_substr(
                $text,
                $offset,
                18000
            );

            $chunks[] = $chunk;
            $offset += 18000;
        }

        return $chunks;
    }

    protected function isImage(
        string $mimeType,
        string $originalName
    ): bool {
        if (str_starts_with($mimeType, 'image/')) {
            return true;
        }

        return in_array(
            strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            ),
            ['png', 'jpg', 'jpeg', 'webp'],
            true
        );
    }
}
