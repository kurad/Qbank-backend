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
                'gradeLevel:id,grade_name',
                'units.topics.learningObjectives',
            ])->findOrFail($import->grade_subject_id);

            $absolutePath = Storage::disk('local')
                ->path($import->file_path);

            $mimeType = strtolower((string) $import->mime_type);

            // Extract media candidates independently; teachers confirm each image-to-question link.
            app(QuestionnaireImportMediaService::class)->extract($import, $absolutePath);

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

                $payloads = [];
                if (mb_strlen(trim($text)) < 20 && str_ends_with(strtolower($import->original_name), '.pdf')) {
                    // Scanned PDF fallback: use bounded visual page previews with the existing vision service.
                    $pages = array_values(array_filter(
                        app(QuestionnaireImportMediaService::class)->assets($import),
                        fn ($asset) => str_starts_with(basename($asset['path']), 'page-') || str_starts_with(basename($asset['path']), 'pdf-')
                    ));
                    if (!$pages) {
                        throw new RuntimeException('Scanned PDF requires pdftoppm for visual extraction.');
                    }
                    foreach (array_slice($pages, 0, 8) as $page) {
                        $payloads[] = $this->extractFromImage(
                            Storage::disk('public')->path($page['path']),
                            'image/png',
                            $gradeSubject
                        );
                    }
                    $import->update(['extracted_text' => '[Scanned PDF: visual extraction from up to 8 pages. Review all questions and diagrams before approval.]']);
                } else {
                    if (mb_strlen(trim($text)) < 20) {
                        throw new RuntimeException('Very little text could be extracted from this questionnaire.');
                    }
                    $import->update(['extracted_text' => mb_substr($text, 0, 200000)]);
                    foreach ($this->chunkText($text) as $chunk) {
                        $payloads[] = $this->extractFromText($chunk, $gradeSubject);
                    }
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

            // For text-based PDFs, recover source tables visually when the text
            // model omitted them. This never assigns an arbitrary diagram or fabricates
            // data: ambiguous tables are left for explicit teacher correction.
            if (str_ends_with(strtolower($import->original_name), '.pdf')
                && isset($text) && mb_strlen(trim($text)) >= 20) {
                $this->recoverMissingPdfTables($absolutePath, $text, $questions);
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
                        'stimulus' => $normalized['stimulus'],
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

    /**
     * Recover table structures from the original page (not from AI guesses).
     * Bounded to 4 requests per import, to control time and provider costs.
     */
    protected function recoverMissingPdfTables(string $pdf, string $extractedText, array &$questions): void
    {
        $renderer = trim((string) shell_exec('command -v pdftoppm 2>/dev/null'));
        if (!$renderer || !is_executable($renderer)) return;
        $pages = preg_split('/\f/u', $extractedText) ?: [];
        if (!$pages) return;
        $calls = 0;
        foreach ($questions as &$question) {
            if ($calls >= 4) break;
            $promptText = (string) ($question['question'] ?? '');
            if (!preg_match('/\b(?:schema\s+(?:below|shown)|(?:following|given|students|marks)\s+table|tables?\s+(?:below|above|shown)|using\s+the\s+\w+\s+table)\b/iu', $promptText)) continue;
            $existingCount = count((array) ($question['supporting_tables'] ?? []));
            $mayRequireMultiple = preg_match('/\bschema\s+(?:below|shown)\b/iu', $promptText);
            if ($existingCount > 0 && (!$mayRequireMultiple || $existingCount >= 2)) continue;
            $first = trim(preg_replace('/\s+/u', ' ', mb_substr($promptText, 0, 110)));
            $tokens = preg_split('/\s+/u', mb_strtolower($first)) ?: [];
            $tokens = array_slice(array_filter($tokens, fn($t) => mb_strlen($t) > 3), 0, 8);
            $matched = null;
            $sourceNumber = preg_replace('/\D+/u', '', (string) ($question['source_reference'] ?? ''));
            foreach ($pages as $index => $page) {
                if (!trim($page)) continue;
                $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $page));
                $hits = 0;
                foreach ($tokens as $token) if (str_contains($normalized, $token)) $hits++;
                $numberMatch = $sourceNumber !== '' && preg_match('/\b(?:question\s*)?' . preg_quote($sourceNumber, '/') . '[\.:\)]/iu', $page);
                if ($hits >= 5 || ($numberMatch && $hits >= 3)) {
                    $matched = $index + 1; break;
                }
            }
            if ($matched === null) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'rh-table-');
            if (!$tmp) continue;
            @unlink($tmp);
            $image = $tmp . '.png';
            $command = escapeshellarg($renderer) . ' -f ' . (int)$matched . ' -l ' . (int)$matched
                . ' -singlefile -scale-to 1800 -png ' . escapeshellarg($pdf)
                . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
            exec($command, $ignored, $exit);
            if ($exit !== 0 || !is_file($image)) { @unlink($image); continue; }
            $calls++;
            try {
                $recovered = $this->vision->extractImageAsJson($image, 'image/png',
                    'Read the ORIGINAL PAGE image. Extract ONLY tables explicitly supporting this question: '
                    . mb_substr($promptText, 0, 700) . '. Return each table with its exact title, headers, and rows. '
                    . 'Never invent values. Ignore unrelated tables on the page. If unsure, return an empty list.',
                    [
                        'type' => 'OBJECT',
                        'properties' => [
                            'tables' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'title' => ['type' => 'STRING'],
                                        'headers' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                                        'rows' => ['type' => 'ARRAY', 'items' => [
                                            'type' => 'ARRAY', 'items' => ['type' => 'STRING']
                                        ]],
                                    ],
                                    'required' => ['title', 'headers', 'rows'],
                                ],
                            ],
                        ],
                        'required' => ['tables'],
                    ]);
                $tables = array_values(array_filter((array) ($recovered['tables'] ?? []),
                    fn ($t) => is_array($t) && count($t['headers'] ?? []) >= 2 && count($t['rows'] ?? []) >= 1));
                // A visual extraction is still teacher-reviewable, never self-approved.
                if (count($tables) > $existingCount) {
                    $question['supporting_tables'] = array_slice($tables, 0, 8);
                }
            } catch (\Throwable $e) {
                \Log::warning('Questionnaire visual table recovery skipped', [
                    'page' => $matched, 'error' => $e->getMessage()
                ]);
            } finally { @unlink($image); }
        }
        unset($question);
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
            throw new RuntimeException('Unable to open DOCX questionnaire.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) {
            throw new RuntimeException('DOCX does not contain readable document text.');
        }

        // Walk blocks in document order; strip_tags() loses table boundaries.
        $document = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        if (!$loaded) {
            throw new RuntimeException('Unable to read DOCX structure.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xpath->query('//w:body')->item(0);
        if (!$body) {
            throw new RuntimeException('DOCX contains no document body.');
        }
        $blocks = [];
        foreach ($body->childNodes as $node) {
            if ($node->localName === 'p') {
                $line = $this->docxNodeText($xpath, $node);
                if ($xpath->query('.//w:drawing | .//w:pict', $node)->length > 0) {
                    // The image is extracted separately for teacher attachment. Do not contaminate question text.
                    $line .= ' [SOURCE FIGURE PRESENT: attach the correct image during review]';
                }
                if (trim($line) !== '') $blocks[] = $line;
            } elseif ($node->localName === 'tbl') {
                $rows = [];
                foreach ($xpath->query('./w:tr', $node) as $tr) {
                    $cells = [];
                    foreach ($xpath->query('./w:tc', $tr) as $cell) {
                        $cells[] = str_replace('|', '\|', $this->docxNodeText($xpath, $cell));
                    }
                    if ($cells) $rows[] = $cells;
                }
                if ($rows) {
                    $width = max(array_map('count', $rows));
                    $blocks[] = '[SUPPORTING TABLE START]';
                    foreach ($rows as $i => $row) {
                        $blocks[] = '| ' . implode(' | ', array_pad($row, $width, '')) . ' |';
                        if ($i === 0) $blocks[] = '| ' . implode(' | ', array_fill(0, $width, '---')) . ' |';
                    }
                    $blocks[] = '[SUPPORTING TABLE END]';
                }
            }
        }
        return trim(implode("\n", $blocks));
    }

    protected function docxNodeText(\DOMXPath $xpath, \DOMNode $node): string
    {
        $parts = [];
        foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $node) as $piece) {
            $parts[] = $piece->localName === 't' ? $piece->textContent
                : ($piece->localName === 'tab' ? "\t" : "\n");
        }
        return trim(implode('', $parts));
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
                    "Preserve multipart question numbering and original line breaks. " .
                    "Copy every source table to supporting_tables, not to question text. " .
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
16. For EACH question preserve required supporting tables as supporting_tables, with
    title (string), headers (array of column-name strings), and rows (arrays of cell strings).
    Convert source tables faithfully; never make up missing rows or values.
17. Preserve associated code/SQL snippets as supporting_code (array of verbatim strings).
    Only include content actually present in the supplied source. Do not include answer choices as tables.
18. NEVER put extraction warnings, OCR notes, or source figure markers in the question text.
    Markers like [SOURCE FIGURE PRESENT: ...] indicate an asset for the teacher to attach.
19. For multipart questions (e.g. Question 42 with numbered parts 1–5), preserve one
    open_ended question with all its numbered subparts on separate lines and original order.
    Do not combine the parts into a single sentence and do not drop their numbering.
20. Source tables immediately following 'schema below'/'table below' belong to that
    question and MUST be represented in supporting_tables. Preserve ALL headers,
    rows and distinct table titles (e.g. Students Table and Marks Table).
    Pay special attention to whitespace-aligned tables in PDF text.
21. Do not report source tables in question text; place them only in supporting_tables.
    Do not invent missing table rows, columns, diagrams or expected answers.
22. If a required source table is illegible, leave it unpopulated and let the
    approval workflow flag the question for the teacher.
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
                            'supporting_tables' => [
                                'type' => 'ARRAY', 'nullable' => true,
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'title' => ['type' => 'STRING'],
                                        'headers' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                                        'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]],
                                    ],
                                    'required' => ['title','headers','rows'],
                                ],
                            ],
                            'supporting_code' => ['type' => 'ARRAY', 'nullable' => true, 'items' => ['type' => 'STRING']],
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
            ($gradeSubject->gradeLevel?->grade_name ?? 'Grade') .
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

        $question = trim((string) ($raw['question'] ?? ''));
        // Old vision prompts could embed review-only warnings into student question text.
        $question = preg_replace(
            '/\[(?:EMBEDDED IMAGE\/DIAGRAM|SOURCE FIGURE PRESENT)[^\]]*\]/iu',
            '', $question
        );
        $question = trim(preg_replace('/[ \t]{2,}/u', ' ', $question));

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
            'stimulus' => $this->normalizeStimulus($raw),
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

    protected function normalizeStimulus(array $raw): array
    {
        $items = [];
        foreach (array_slice((array) ($raw['supporting_tables'] ?? []), 0, 8) as $table) {
            if (!is_array($table)) continue;
            $headers = array_slice(array_map('strval', (array) ($table['headers'] ?? [])), 0, 12);
            if (!$headers) continue;
            $columns = [];
            foreach ($headers as $index => $header) {
                $columns[] = ['key' => 'column_' . ($index + 1), 'label' => $header];
            }
            $rows = [];
            foreach (array_slice((array) ($table['rows'] ?? []), 0, 100) as $cells) {
                if (!is_array($cells)) continue;
                $row = [];
                foreach ($columns as $index => $column) {
                    $row[$column['key']] = (string) ($cells[$index] ?? '');
                }
                $rows[] = $row;
            }
            $items[] = ['type' => 'table', 'title' => (string) ($table['title'] ?? ''), 'columns' => $columns, 'rows' => $rows];
        }
        foreach (array_slice((array) ($raw['supporting_code'] ?? []), 0, 8) as $code) {
            if (is_string($code) && trim($code) !== '') {
                $items[] = ['type' => 'code', 'title' => '', 'language' => 'text', 'content' => $code];
            }
        }
        return $items;
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
