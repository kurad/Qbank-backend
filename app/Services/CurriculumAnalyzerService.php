<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\CurriculumAnalysis;
use App\Services\AI\AIGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CurriculumAnalyzerService
{
    public function __construct(
        protected AIGateway $ai
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
     */
    public function analyze(
        CourseMaterial $material,
        int $teacherId,
        ?int $schoolId,
        ?string $instruction = null,
        ?int $parentAnalysisId = null
    ): CurriculumAnalysis {

        $material->loadMissing([
            'unit.gradeSubject',
            'subject',
        ]);

        if ($material->scope !== 'unit') {
            throw new RuntimeException(
                'Only unit-level materials can be analyzed as curriculum.'
            );
        }

        if (!$material->unit_id || !$material->unit) {
            throw new RuntimeException(
                'This course material is not associated with a valid unit.'
            );
        }

        $unit = $material->unit;

        $gradeSubject = $unit->gradeSubject;

        if (!$gradeSubject) {
            throw new RuntimeException(
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
            throw new RuntimeException(
                'This course material does not belong to your school.'
            );
        }

        if (!$material->subject_id || !$material->subject) {
            throw new RuntimeException(
                'This course material is not associated with a valid subject.'
            );
        }

        if ((int) $material->subject_id !== (int) $gradeSubject->subject_id) {
            throw new RuntimeException(
                'The course material subject does not match the teaching area.'
            );
        }

        if (
            !$material->extracted_text ||
            trim($material->extracted_text) === ''
        ) {
            throw new RuntimeException(
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
                return $existing->load([
                    'topics.objectives',
                ]);
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
            | Build curriculum prompt
            |--------------------------------------------------------------------------
            */

            $prompt = $this->buildCurriculumPrompt(
                $material,
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

            $schema = [
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

                                            'order' => [
                                                'type' => 'INTEGER',
                                            ],
                                        ],

                                        'required' => [
                                            'objective',
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

            /*
            |--------------------------------------------------------------------------
            | Ask AI for structured curriculum JSON
            |--------------------------------------------------------------------------
            |
            | Routing:
            | Gemini → Groq fallback
            |
            */

            $result = $this->ai->curriculumJson(
                $messages,
                $schema,
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
                throw new RuntimeException(
                    'The AI did not identify any curriculum topics from this material.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Save proposed curriculum structure
            |--------------------------------------------------------------------------
            */

            DB::transaction(function () use (
                $analysis,
                $topics
            ) {
                foreach ($topics as $topicIndex => $topicData) {
                    $topic = $analysis->topics()->create([
                        'name' => $topicData['name'],

                        'description' =>
                        $topicData['description']
                            ?? null,

                        'order' =>
                        $topicData['order']
                            ?? ($topicIndex + 1),

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

                            'order' =>
                            $objectiveData['order']
                                ?? ($objectiveIndex + 1),

                            'status' => 'proposed',
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Mark analysis as ready
                |--------------------------------------------------------------------------
                */

                $analysis->update([
                    'status' => 'ready',
                    'analyzed_at' => now(),
                    'analysis_error' => null,
                ]);
            });
        } catch (\Throwable $e) {
            /*
            |--------------------------------------------------------------------------
            | Mark analysis as failed
            |--------------------------------------------------------------------------
            */

            $analysis->update([
                'status' => 'failed',
                'analysis_error' => $e->getMessage(),
            ]);

            throw $e;
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
     * Build the curriculum organization prompt.
     */
    /**
     * Build the curriculum organization prompt.
     */
    protected function buildCurriculumPrompt(
        CourseMaterial $material,
        ?string $instruction = null,
        ?int $parentAnalysisId = null
    ): string {
        $material->loadMissing([
            'unit',
            'subject',
        ]);

        $materialText = trim(
            mb_substr(
                $material->extracted_text,
                0,
                60000
            )
        );

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

Do not simply shorten or rename the previous analysis.
Reorganize the curriculum structure when necessary.

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

UNIT:
{$material->unit->name}

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

7. Learning objectives should describe observable student learning.

8. Be conservative when the source material is ambiguous.

9. Do not add unrelated curriculum content simply because it is
   common knowledge for this subject.

10. When revising a previous analysis, create a NEW curriculum
    structure rather than copying the previous structure unchanged.

11. The proposed structure will be reviewed by the teacher before
    becoming part of the official curriculum.

12. Return ONLY valid JSON.

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
     */
    protected function normaliseTopics(
        array $topics
    ): array {
        $normalized = [];

        foreach ($topics as $topicIndex => $topic) {
            if (!is_array($topic)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Topic name
            |--------------------------------------------------------------------------
            */

            $name = trim(
                (string) (
                    $topic['name']
                    ?? ''
                )
            );

            if ($name === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Learning objectives
            |--------------------------------------------------------------------------
            */

            $objectives = [];

            $rawObjectives =
                $topic['learning_objectives']
                ?? [];

            if (!is_array($rawObjectives)) {
                $rawObjectives = [];
            }

            foreach (
                $rawObjectives as $objectiveIndex => $objective
            ) {
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

                $objectives[] = [
                    'code' =>
                    !empty($objective['code'])
                        ? trim(
                            (string)
                            $objective['code']
                        )
                        : null,

                    'objective' =>
                    $objectiveText,

                    'description' =>
                    !empty($objective['description'])
                        ? trim(
                            (string)
                            $objective['description']
                        )
                        : null,

                    'order' =>
                    isset($objective['order'])
                        ? (int)
                        $objective['order']
                        : ($objectiveIndex + 1),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | A topic without learning objectives
            | is not useful for our curriculum structure.
            |--------------------------------------------------------------------------
            */

            if (empty($objectives)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Add normalized topic
            |--------------------------------------------------------------------------
            */

            $normalized[] = [
                'name' => $name,

                'description' =>
                !empty($topic['description'])
                    ? trim(
                        (string)
                        $topic['description']
                    )
                    : null,

                'order' =>
                isset($topic['order'])
                    ? (int)
                    $topic['order']
                    : ($topicIndex + 1),

                'learning_objectives' =>
                $objectives,
            ];
        }

        return $normalized;
    }
}
