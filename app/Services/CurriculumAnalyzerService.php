<?php

namespace App\Services;

use App\Exceptions\CurriculumException;
use App\Models\CourseMaterial;
use App\Models\CurriculumAnalysis;
use App\Models\Topic;
use App\Services\AI\AIGateway;
use App\Support\TextTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CurriculumAnalyzerService
{
    /**
     * How much of the extracted text is sent to the AI. Anything beyond this
     * is NOT analysed; the analysis records that it was truncated so the
     * teacher is told (see curriculum_analyses.source_truncated).
     */
    public const MAX_SOURCE_CHARS = 120000;

    /** A "processing" analysis older than this is assumed to have died. */
    public const STALE_PROCESSING_MINUTES = 15;

    /** Cap on how many refused objectives are stored for the teacher to read. */
    private const MAX_DROPPED_RECORDED = 60;

    public function __construct(
        protected AIGateway $ai,
        protected ObjectiveContentSufficiencyService $sufficiency
    ) {}

    /**
     * Analyze a unit-level course material and create
     * a proposed curriculum structure.
     *
     * AI routing:
     * Gemini → Groq fallback
     *
     * Ownership:
     * Course Material
     *     ↓
     * Unit
     *     ↓
     * GradeSubject / Teaching Area
     *     ↓
     * Teacher + School
     *
     * Only objectives whose content the material can actually teach are
     * created (see ObjectiveContentSufficiencyService).
     *
     * @throws CurriculumException for any problem that is safe to show the teacher
     */
    public function analyze(
        CourseMaterial $material,
        int $teacherId,
        ?int $schoolId,
        ?string $instruction = null,
        ?int $parentAnalysisId = null
    ): CurriculumAnalysis {

        $material->loadMissing([
            'unit.gradeSubject.gradeLevel',
            'subject',
        ]);

        if ($material->scope !== 'unit') {
            throw new CurriculumException(
                'Only unit-level materials can be analyzed as curriculum.'
            );
        }

        if (!$material->unit_id || !$material->unit) {
            throw new CurriculumException(
                'This course material is not associated with a valid unit.'
            );
        }

        $unit = $material->unit;

        $gradeSubject = $unit->gradeSubject;

        if (!$gradeSubject) {
            throw new CurriculumException(
                'This unit is not associated with a valid teaching area.'
            );
        }

        $gradeSubjectSchoolId = $gradeSubject->school_id !== null
            ? (int) $gradeSubject->school_id
            : null;

        $userSchoolId = $schoolId !== null
            ? (int) $schoolId
            : null;

        $allowed =
            $gradeSubjectSchoolId === null ||
            $userSchoolId === null ||
            $gradeSubjectSchoolId === $userSchoolId;

        if (!$allowed) {
            throw new CurriculumException(
                'This course material does not belong to your school.'
            );
        }

        if (!$material->subject_id || !$material->subject) {
            throw new CurriculumException(
                'This course material is not associated with a valid subject.'
            );
        }

        if ((int) $material->subject_id !== (int) $gradeSubject->subject_id) {
            throw new CurriculumException(
                'The course material subject does not match the teaching area.'
            );
        }

        if (
            !$material->extracted_text ||
            trim($material->extracted_text) === ''
        ) {
            throw new CurriculumException(
                'This course material does not contain extracted text.'
            );
        }

        if ($instruction === null) {
            $existing = CurriculumAnalysis::query()
                ->where('course_material_id', $material->id)
                ->whereIn('status', [
                    'processing',
                    'ready',
                ])
                ->latest('id')
                ->first();

            if ($existing) {
                /*
                 * An analysis stuck in "processing" (the request or worker
                 * died mid-way) would otherwise be returned forever and the
                 * material could never be analysed again.
                 */
                $stale = $existing->status === 'processing'
                    && $existing->updated_at
                    && $existing->updated_at->lt(
                        now()->subMinutes(self::STALE_PROCESSING_MINUTES)
                    );

                if ($stale) {
                    $existing->update([
                        'status' => 'failed',
                        'analysis_error' =>
                            'The previous analysis did not finish. A new one was started.',
                    ]);
                } else {
                    return $existing->load([
                        'topics.objectives',
                    ]);
                }
            }
        }

        $latestVersion = CurriculumAnalysis::query()
            ->where('course_material_id', $material->id)
            ->max('version');

        $version = ((int) $latestVersion) + 1;

        $analysis = CurriculumAnalysis::create([
            'course_material_id' => $material->id,
            'unit_id' => $material->unit_id,
            'teacher_id' => $teacherId,
            'status' => 'processing',
            'version' => $version,
            'instruction' => $instruction,
            'parent_analysis_id' => $parentAnalysisId,
        ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Source text (and whether it had to be cut)
            |--------------------------------------------------------------------------
            */

            $source = $this->prepareSource((string) $material->extracted_text);

            /*
            |--------------------------------------------------------------------------
            | Build curriculum prompt
            |--------------------------------------------------------------------------
            */

            $prompt = $this->buildCurriculumPrompt(
                $material,
                $source['text'],
                $instruction,
                $parentAnalysisId
            );

            $messages = [
                [
                    'role' => 'system',
                    'content' =>
                    'You are an expert curriculum organization assistant. ' .
                        'Use teacher-provided educational material as the primary source. ' .
                        'Do not invent unsupported curriculum content. ' .
                        'Return only valid JSON when JSON is requested.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ];

            $result = $this->ai->curriculumJson(
                $messages,
                $this->responseSchema(),
                [
                    'temperature' => 0.2,
                    'max_tokens' => 8000,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Normalize AI response
            |--------------------------------------------------------------------------
            */

            $topics = $this->normaliseTopics(
                $result['topics'] ?? []
            );

            if (empty($topics)) {
                throw new CurriculumException(
                    'The AI did not identify any curriculum topics from this material.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Content-sufficiency gate
            |--------------------------------------------------------------------------
            |
            | Only keep objectives the material has enough content to teach.
            |
            */

            [$topics, $dropped] = $this->applyContentGate(
                $source['text'],
                $topics
            );

            if (empty($topics)) {
                $analysis->update([
                    'dropped_objectives' => $dropped,
                ]);

                throw (new CurriculumException(
                    'The material does not contain enough teachable content to create learning objectives. ' .
                    'Upload fuller teaching material (explanations and examples, not just headings or lists) ' .
                    'and analyse it again.'
                ))->withContext(['dropped_objectives' => $dropped]);
            }

            /*
            |--------------------------------------------------------------------------
            | Save proposed curriculum structure
            |--------------------------------------------------------------------------
            */

            DB::transaction(function () use (
                $analysis,
                $topics,
                $dropped,
                $source
            ) {
                foreach ($topics as $topicIndex => $topicData) {
                    $topic = $analysis->topics()->create([
                        'name' => $topicData['name'],

                        'description' =>
                        $topicData['description']
                            ?? null,

                        'order' => $topicIndex + 1,

                        'status' => 'proposed',
                    ]);

                    foreach (
                        $topicData['learning_objectives']
                        as $objectiveIndex => $objectiveData
                    ) {
                        $topic->objectives()->create([
                            'code' =>
                            $objectiveData['code']
                                ?? null,

                            'objective' =>
                            $objectiveData['objective'],

                            'description' =>
                            $objectiveData['description']
                                ?? null,

                            'source_excerpt' =>
                            $objectiveData['source_excerpt']
                                ?? null,

                            'order' => $objectiveIndex + 1,

                            'status' => 'proposed',
                        ]);
                    }
                }

                $analysis->update([
                    'status' => 'ready',
                    'analyzed_at' => now(),
                    'analysis_error' => null,
                    'source_truncated' => $source['truncated'],
                    'source_chars_total' => $source['total'],
                    'source_chars_used' => $source['used'],
                    'dropped_objectives' => $dropped ?: null,
                ]);
            });
        } catch (Throwable $e) {
            /*
            |--------------------------------------------------------------------------
            | Mark analysis as failed
            |--------------------------------------------------------------------------
            |
            | analysis_error is returned to the browser, so it only ever holds a
            | message that is safe to show. The real exception is logged.
            |
            */

            $safe = $e instanceof CurriculumException
                ? $e
                : new CurriculumException(
                    'The AI service could not complete the analysis. Please try again.',
                    0,
                    $e
                );

            Log::error('Curriculum analysis failed.', [
                'analysis_id' => $analysis->id,
                'course_material_id' => $material->id,
                'exception' => $e,
            ]);

            $analysis->update([
                'status' => 'failed',
                'analysis_error' => $safe->getMessage(),
            ]);

            throw $safe;
        }

        /*
        |--------------------------------------------------------------------------
        | Return fresh analysis
        |--------------------------------------------------------------------------
        */

        return $analysis->fresh([
            'topics.objectives',
        ]);
    }

    /**
     * Trim the source to what is sent to the AI, preferring to cut at a
     * paragraph boundary, and report whether anything was left out.
     *
     * @return array{text:string, truncated:bool, total:int, used:int}
     */
    protected function prepareSource(string $extractedText): array
    {
        $text = trim($extractedText);
        $total = mb_strlen($text);

        if ($total <= self::MAX_SOURCE_CHARS) {
            return ['text' => $text, 'truncated' => false, 'total' => $total, 'used' => $total];
        }

        $cut = mb_substr($text, 0, self::MAX_SOURCE_CHARS);
        $lastBreak = mb_strrpos($cut, "\n\n");

        if ($lastBreak !== false && $lastBreak > self::MAX_SOURCE_CHARS * 0.9) {
            $cut = mb_substr($cut, 0, $lastBreak);
        }

        $cut = trim($cut);

        return ['text' => $cut, 'truncated' => true, 'total' => $total, 'used' => mb_strlen($cut)];
    }

    /**
     * JSON schema for the topic/objective structure.
     */
    protected function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',

            'properties' => [
                'topics' => [
                    'type' => 'ARRAY',

                    'items' => [
                        'type' => 'OBJECT',

                        'properties' => [
                            'name' => [
                                'type' => 'STRING',
                            ],

                            'description' => [
                                'type' => 'STRING',
                                'nullable' => true,
                            ],

                            'order' => [
                                'type' => 'INTEGER',
                            ],

                            'learning_objectives' => [
                                'type' => 'ARRAY',

                                'items' => [
                                    'type' => 'OBJECT',

                                    'properties' => [
                                        'code' => [
                                            'type' => 'STRING',
                                            'nullable' => true,
                                        ],

                                        'objective' => [
                                            'type' => 'STRING',
                                        ],

                                        'description' => [
                                            'type' => 'STRING',
                                            'nullable' => true,
                                        ],

                                        'source_excerpt' => [
                                            'type' => 'STRING',
                                        ],

                                        'order' => [
                                            'type' => 'INTEGER',
                                        ],
                                    ],

                                    'required' => [
                                        'objective',
                                        'source_excerpt',
                                        'order',
                                    ],
                                ],
                            ],
                        ],

                        'required' => [
                            'name',
                            'order',
                            'learning_objectives',
                        ],
                    ],
                ],
            ],

            'required' => [
                'topics',
            ],
        ];
    }

    /**
     * Build the curriculum organization prompt.
     */
    protected function buildCurriculumPrompt(
        CourseMaterial $material,
        string $materialText,
        ?string $instruction = null,
        ?int $parentAnalysisId = null
    ): string {
        $material->loadMissing([
            'unit.gradeSubject.gradeLevel',
            'subject',
        ]);

        $gradeName = trim((string) (
            $material->unit?->gradeSubject?->gradeLevel?->grade_name ?? ''
        ));

        $gradeLine = $gradeName !== '' ? $gradeName : 'Not specified';

        /*
        |--------------------------------------------------------------------------
        | Topics already in this unit (first analysis only)
        |--------------------------------------------------------------------------
        |
        | Approval refuses a topic whose name already exists as an active topic
        | in the unit, so tell the AI not to propose duplicates. A revision
        | replaces its parent, so it does not need this list.
        |
        */

        $existingTopicsSection = '';

        if (!$parentAnalysisId) {
            $existingNames = Topic::query()
                ->where('unit_id', $material->unit_id)
                ->where('status', 'active')
                ->orderBy('order')
                ->pluck('topic_name')
                ->filter()
                ->values()
                ->all();

            if ($existingNames) {
                $list = implode("\n", array_map(
                    fn ($name) => '- ' . $name,
                    array_slice($existingNames, 0, 60)
                ));

                $existingTopicsSection = <<<TEXT

TOPICS ALREADY IN THIS UNIT:
{$list}

Do not propose a topic with the same name as one above. If this material
covers one of them, use a more specific name that describes what this
material adds.
TEXT;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Previous analysis
    |--------------------------------------------------------------------------
    |
    | When this is a refinement, give the AI the previous proposed
    | curriculum structure as context. The AI can then reconsider
    | that structure based on the teacher's new instruction.
    |
    */

        $previousAnalysisSection = '';

        if ($parentAnalysisId) {
            $previousAnalysis = CurriculumAnalysis::query()
                ->with([
                    'topics.objectives',
                ])
                ->find($parentAnalysisId);

            if ($previousAnalysis) {
                $previousTopics = $previousAnalysis->topics
                    ->sortBy('order')
                    ->map(function ($topic) {
                        return [
                            'name' => $topic->name,
                            'description' => $topic->description,
                            'order' => $topic->order,
                            'learning_objectives' => $topic->objectives
                                ->sortBy('order')
                                ->map(function ($objective) {
                                    return [
                                        'code' => $objective->code,
                                        'objective' => $objective->objective,
                                        'description' => $objective->description,
                                        'order' => $objective->order,
                                    ];
                                })
                                ->values()
                                ->all(),
                        ];
                    })
                    ->values()
                    ->all();

                $previousAnalysisJson = json_encode(
                    [
                        'analysis_id' => $previousAnalysis->id,
                        'version' => $previousAnalysis->version,
                        'topics' => $previousTopics,
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                );

                $previousAnalysisSection = <<<TEXT

PREVIOUS CURRICULUM ANALYSIS:
{$previousAnalysisJson}

The previous analysis is provided as context only.

You are creating a NEW proposed curriculum analysis.
Do not assume that the previous structure is correct.
Reconsider it according to the teacher's instruction and the
source material.
TEXT;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Teacher refinement instruction
    |--------------------------------------------------------------------------
    */

        $instructionSection = '';

        if ($instruction !== null && trim($instruction) !== '') {
            $teacherInstruction = trim($instruction);

            $instructionSection = <<<TEXT

TEACHER'S REFINEMENT INSTRUCTION:
{$teacherInstruction}

This instruction is important.

Follow the teacher's requested change when it is compatible
with the source material.

For example, if the teacher asks for a specific number of
topics, produce that number of coherent topics where the
source material supports it.

Keep every part of the previous structure that the instruction
does not ask you to change (names, wording and order), and
reorganize only what the instruction requires. Do not rewrite
unaffected topics or objectives.

An instruction can never override the evidence rules below: if the
teacher asks for content the material does not teach, leave it out.

The teacher will review the result before it becomes the
official curriculum.
TEXT;
        }

        return <<<PROMPT
You are an expert curriculum organization assistant.

Your task is to organize teacher-provided educational material
into a logical curriculum structure.

SUBJECT:
{$material->subject->name}

GRADE LEVEL:
{$gradeLine}

UNIT:
{$material->unit->name}
{$existingTopicsSection}

SOURCE MATERIAL:
{$materialText}

{$previousAnalysisSection}

{$instructionSection}

Identify the topics covered by this material.

For each topic, identify the learning objectives that are
supported by the source material.

RULES:

1. Use the teacher-provided material as the primary source.

2. If a teacher refinement instruction is provided, follow it
   while remaining faithful to the source material.

3. Do not invent topics that are not supported by the material.

4. Do not invent learning objectives that are not reasonably
   supported by the material.

5. Preserve important terminology from the teacher's material.

6. Arrange topics in a logical teaching sequence.

7. Each learning objective must describe ONE observable skill or
   piece of understanding, starting with a measurable verb
   (for example: define, calculate, compare, classify, solve,
   construct, interpret, describe, label). Do not use vague verbs
   such as "understand", "know", "learn" or "appreciate".
   Pitch the depth for the GRADE LEVEL above.

8. Be conservative when the source material is ambiguous.

9. Do not add unrelated curriculum content simply because it is
   common knowledge for this subject.

10. When revising a previous analysis, keep the parts the teacher
    did not ask to change, and reorganize only what the
    instruction requires.

11. The proposed structure will be reviewed by the teacher before
    becoming part of the official curriculum.

12. Granularity: prefer roughly 3 to 12 topics for a unit, and
    about 2 to 6 objectives per topic. A topic must be a coherent
    theme, not a single sentence of the material. Do not split one
    idea into several near-identical objectives.

13. EVIDENCE (mandatory): every objective must include
    "source_excerpt", an EXACT, word-for-word quotation copied from
    the SOURCE MATERIAL above (about 25 to 120 words, one or two
    consecutive passages) that explains or demonstrates what the
    objective asks the student to do. Never paraphrase, summarize
    or combine distant passages in source_excerpt.

14. ONLY propose an objective if the source material actually
    contains enough to TEACH it: an explanation, definition, rule,
    procedure or worked example. A topic that is only named in a
    heading, a contents list or a single passing sentence is NOT
    enough: leave that objective out entirely. It is better to
    return fewer objectives than to include one the material cannot
    teach.

15. Return ONLY valid JSON.

REQUIRED JSON STRUCTURE:

{
    "topics": [
        {
            "name": "Topic name",
            "description": "Short description of the topic",
            "order": 1,
            "learning_objectives": [
                {
                    "code": null,
                    "objective": "Student should be able to ...",
                    "description": null,
                    "source_excerpt": "Exact quotation from the source material",
                    "order": 1
                }
            ]
        }
    ]
}

IMPORTANT:

- Every topic must contain at least one learning objective.
- Use null for code when no objective code exists in the source.
- Do not include markdown.
- Do not include explanations outside the JSON.
PROMPT;
    }

    /**
     * Normalize and validate AI-generated topics.
     *
     * - Topic and objective order always follow the array position; the
     *   AI's own "order" numbers are not trusted.
     * - Topics that share a name are merged (approval would otherwise fail
     *   on the duplicate), and repeated objectives are dropped.
     */
    protected function normaliseTopics(
        array $topics
    ): array {
        $normalized = [];
        $topicPositionByName = [];

        foreach ($topics as $topic) {
            if (!is_array($topic)) {
                continue;
            }

            $name = trim(
                (string) (
                    $topic['name']
                    ?? ''
                )
            );

            $name = mb_substr($name, 0, 255);

            if ($name === '') {
                continue;
            }

            $rawObjectives =
                $topic['learning_objectives']
                ?? [];

            if (!is_array($rawObjectives)) {
                $rawObjectives = [];
            }

            $objectives = $this->normaliseObjectives($rawObjectives);

            /*
            | A topic without learning objectives
            | is not useful for our curriculum structure.
            */

            if (empty($objectives)) {
                continue;
            }

            $nameKey = mb_strtolower($name);

            if (isset($topicPositionByName[$nameKey])) {
                $position = $topicPositionByName[$nameKey];

                $normalized[$position]['learning_objectives'] = $this->mergeObjectives(
                    $normalized[$position]['learning_objectives'],
                    $objectives
                );

                continue;
            }

            $topicPositionByName[$nameKey] = count($normalized);

            $normalized[] = [
                'name' => $name,

                'description' =>
                !empty($topic['description'])
                    ? trim((string) $topic['description'])
                    : null,

                'learning_objectives' => $objectives,
            ];
        }

        foreach ($normalized as $topicIndex => $topic) {
            $normalized[$topicIndex]['order'] = $topicIndex + 1;

            foreach ($topic['learning_objectives'] as $objectiveIndex => $objective) {
                $normalized[$topicIndex]['learning_objectives'][$objectiveIndex]['order'] = $objectiveIndex + 1;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array{code:?string, objective:string, description:?string, source_excerpt:?string}>
     */
    protected function normaliseObjectives(array $rawObjectives): array
    {
        $objectives = [];
        $seen = [];

        foreach ($rawObjectives as $objective) {
            if (!is_array($objective)) {
                continue;
            }

            $objectiveText = trim(
                (string) (
                    $objective['objective']
                    ?? ''
                )
            );

            if ($objectiveText === '') {
                continue;
            }

            $key = TextTerms::normalise($objectiveText);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $objectives[] = [
                'code' =>
                !empty($objective['code'])
                    ? mb_substr(trim((string) $objective['code']), 0, 100)
                    : null,

                'objective' => $objectiveText,

                'description' =>
                !empty($objective['description'])
                    ? trim((string) $objective['description'])
                    : null,

                'source_excerpt' =>
                !empty($objective['source_excerpt'])
                    ? trim((string) $objective['source_excerpt'])
                    : null,
            ];
        }

        return $objectives;
    }

    protected function mergeObjectives(array $existing, array $incoming): array
    {
        $seen = [];

        foreach ($existing as $objective) {
            $seen[TextTerms::normalise($objective['objective'])] = true;
        }

        foreach ($incoming as $objective) {
            $key = TextTerms::normalise($objective['objective']);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $existing[] = $objective;
            }
        }

        return $existing;
    }

    /**
     * Keep only objectives the source material can actually teach.
     *
     * @param  array $topics normalised topics
     * @return array{0: array, 1: array} [kept topics, dropped objective records]
     */
    protected function applyContentGate(
        string $sourceText,
        array $topics
    ): array {
        $index = $this->sufficiency->index($sourceText);

        $dropped = [];
        $toJudge = [];

        // Layer 1: the quoted passage must really exist in the material.
        foreach ($topics as $topicIndex => $topic) {
            foreach ($topic['learning_objectives'] as $objectiveIndex => $objective) {
                $check = $this->sufficiency->checkExcerpt(
                    $index,
                    $objective['source_excerpt'] ?? null
                );

                if (!$check['ok']) {
                    $dropped[] = $this->droppedRecord($topic, $objective, $check['reason'], []);
                    $topics[$topicIndex]['learning_objectives'][$objectiveIndex] = null;
                    continue;
                }

                $toJudge["{$topicIndex}:{$objectiveIndex}"] = [
                    'topic' => $topic['name'],
                    'objective' => $objective['objective'],
                    'description' => $objective['description'] ?? null,
                    'evidence' => $this->sufficiency->evidenceFor(
                        $index,
                        $objective['objective'],
                        $objective['description'] ?? null,
                        $objective['source_excerpt']
                    ),
                ];
            }
        }

        // Layer 2: a strict, separate judge decides whether it is enough to teach.
        if ($toJudge) {
            $verdicts = $this->sufficiency->assess($toJudge);

            foreach ($verdicts as $key => $verdict) {
                [$topicIndex, $objectiveIndex] = array_map('intval', explode(':', (string) $key));

                if ($verdict['sufficient']) {
                    continue;
                }

                $objective = $topics[$topicIndex]['learning_objectives'][$objectiveIndex];

                $dropped[] = $this->droppedRecord(
                    $topics[$topicIndex],
                    $objective,
                    $verdict['reason'] !== ''
                        ? $verdict['reason']
                        : 'The material does not contain enough content to teach this objective.',
                    $verdict['missing']
                );

                $topics[$topicIndex]['learning_objectives'][$objectiveIndex] = null;
            }
        }

        // Rebuild: drop refused objectives, then topics left with none.
        $kept = [];

        foreach ($topics as $topic) {
            $objectives = array_values(array_filter(
                $topic['learning_objectives'],
                fn ($objective) => $objective !== null
            ));

            if (!$objectives) {
                continue;
            }

            $topic['learning_objectives'] = $objectives;
            $kept[] = $topic;
        }

        foreach ($kept as $topicIndex => $topic) {
            $kept[$topicIndex]['order'] = $topicIndex + 1;

            foreach ($topic['learning_objectives'] as $objectiveIndex => $objective) {
                $kept[$topicIndex]['learning_objectives'][$objectiveIndex]['order'] = $objectiveIndex + 1;
            }
        }

        return [$kept, array_slice($dropped, 0, self::MAX_DROPPED_RECORDED)];
    }

    protected function droppedRecord(array $topic, array $objective, string $reason, array $missing): array
    {
        return [
            'topic' => mb_substr((string) $topic['name'], 0, 255),
            'objective' => mb_substr((string) $objective['objective'], 0, 400),
            'reason' => mb_substr($reason, 0, 300),
            'missing' => array_slice(
                array_map(fn ($m) => mb_substr((string) $m, 0, 160), $missing),
                0,
                5
            ),
        ];
    }
}
