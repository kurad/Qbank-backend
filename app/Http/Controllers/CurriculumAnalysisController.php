<?php

namespace App\Http\Controllers;

use App\Exceptions\CurriculumException;
use App\Models\CourseMaterial;
use App\Models\CurriculumAnalysis;
use App\Models\LearningObjective;
use App\Models\Topic;
use App\Services\CurriculumAnalyzerService;
use App\Services\KnowledgeChunkingService;
use App\Services\ObjectiveContentSufficiencyService;
use App\Support\TextTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CurriculumAnalysisController extends Controller
{
    /**
     * Analyze a course material for the first time.
     */
    public function analyze(
        CourseMaterial $courseMaterial,
        CurriculumAnalyzerService $analyzer
    ): JsonResponse {
        $user = Auth::user();

        abort_unless(
            $user &&
                in_array(
                    strtolower(trim((string) $user->role)),
                    ['teacher', 'admin'],
                    true
                ),
            403,
            'You are not authorized to analyze course materials.'
        );

        $this->authorizeCourseMaterial($courseMaterial);

        // Analysis runs the AI proposal plus a content-verification pass.
        @set_time_limit(300);

        try {
            $analysis = $analyzer->analyze(
                $courseMaterial,
                (int) $user->id,
                $user->school_id !== null
                    ? (int) $user->school_id
                    : null
            );

            return response()->json([
                'message' => 'Curriculum analysis completed successfully.',
                'data' => $analysis,
            ], 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'Curriculum analysis failed.');
        }
    }

    /**
     * Show a curriculum analysis.
     */
    public function show(
        CurriculumAnalysis $curriculumAnalysis
    ): JsonResponse {
        $this->authorizeAnalysis($curriculumAnalysis);

        return response()->json([
            'data' => $this->withFlags($curriculumAnalysis->load([
                'courseMaterial:id,title,unit_id,subject_id,teacher_id',
                'courseMaterial.supportingSources:id,course_material_id,title,url,domain,status,cached_title,content_type,validated_at,last_error',
                'unit:id,name',
                'topics.objectives',
            ])),
        ]);
    }

    /**
     * Save teacher edits to a ready analysis.
     *
     * New or reworded objectives must pass the same content-sufficiency
     * gate as AI-proposed ones: an objective that the uploaded material
     * cannot teach is refused instead of being created.
     */
    public function update(
        CurriculumAnalysis $curriculumAnalysis,
        ObjectiveContentSufficiencyService $sufficiency
    ): JsonResponse {
        $this->authorizeAnalysis($curriculumAnalysis);

        if ($curriculumAnalysis->status !== 'ready') {
            return response()->json([
                'message' =>
                    'Only a ready curriculum analysis can be edited.',
            ], 422);
        }

        $validator = Validator::make(
            request()->all(),
            [
                'topics' => ['required', 'array', 'min:1'],
                'topics.*.id' => ['nullable', 'integer'],
                'topics.*.name' => ['required', 'string', 'max:255'],
                'topics.*.description' => ['nullable', 'string'],
                'topics.*.order' => ['required', 'integer', 'min:1'],
                'topics.*.objectives' => ['required', 'array', 'min:1'],
                'topics.*.objectives.*.id' => ['nullable', 'integer'],
                'topics.*.objectives.*.code' => ['nullable', 'string', 'max:100'],
                'topics.*.objectives.*.objective' => ['required', 'string'],
                'topics.*.objectives.*.description' => ['nullable', 'string'],
                'topics.*.objectives.*.order' => ['required', 'integer', 'min:1'],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        try {
            // Approval refuses duplicate topic names, so refuse them here.
            $this->assertUniqueTopicNames(
                array_map(fn ($topic) => $topic['name'], $data['topics'])
            );

            // The AI calls happen BEFORE the database transaction.
            $excerpts = $this->verifyEditedObjectives(
                $curriculumAnalysis,
                $data['topics'],
                $sufficiency
            );

            DB::transaction(function () use (
                $curriculumAnalysis,
                $data,
                $excerpts
            ) {
                $locked = CurriculumAnalysis::query()
                    ->whereKey($curriculumAnalysis->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked || $locked->status !== 'ready') {
                    throw new CurriculumException(
                        'This curriculum analysis can no longer be edited.'
                    );
                }

                /*
                 * Remove proposed topics that are no longer
                 * included in the submitted organization.
                 */
                $submittedTopicIds = collect($data['topics'])
                    ->pluck('id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values();

                $curriculumAnalysis
                    ->topics()
                    ->whereNotIn('id', $submittedTopicIds)
                    ->delete();

                foreach ($data['topics'] as $topicIndex => $topicData) {
                    if (!empty($topicData['id'])) {
                        $topic = $curriculumAnalysis
                            ->topics()
                            ->where('id', $topicData['id'])
                            ->first();

                        if (!$topic) {
                            throw new CurriculumException(
                                'One of the selected topics does not belong to this curriculum analysis.'
                            );
                        }

                        $topic->update([
                            'name' => $topicData['name'],
                            'description' => $topicData['description'] ?? null,
                            'order' => $topicData['order'],
                            'status' => 'proposed',
                        ]);
                    } else {
                        $topic = $curriculumAnalysis
                            ->topics()
                            ->create([
                                'name' => $topicData['name'],
                                'description' => $topicData['description'] ?? null,
                                'order' => $topicData['order'],
                                'status' => 'proposed',
                            ]);
                    }

                    /*
                     * Remove objectives that are no longer submitted.
                     */
                    $submittedObjectiveIds = collect($topicData['objectives'])
                        ->pluck('id')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->values();

                    $topic
                        ->objectives()
                        ->whereNotIn('id', $submittedObjectiveIds)
                        ->delete();

                    foreach ($topicData['objectives'] as $objectiveIndex => $objectiveData) {
                        $attributes = [
                            'code' => $objectiveData['code'] ?? null,
                            'objective' => $objectiveData['objective'],
                            'description' => $objectiveData['description'] ?? null,
                            'order' => $objectiveData['order'],
                            'status' => 'proposed',
                        ];

                        // Only new / reworded objectives were verified, so only
                        // they get a (new) supporting passage.
                        $excerptKey = $topicIndex . ':' . $objectiveIndex;

                        if (array_key_exists($excerptKey, $excerpts)) {
                            $attributes['source_excerpt'] = $excerpts[$excerptKey];
                        }

                        if (!empty($objectiveData['id'])) {
                            $objective = $topic
                                ->objectives()
                                ->where('id', $objectiveData['id'])
                                ->first();

                            if (!$objective) {
                                throw new CurriculumException(
                                    'One of the selected learning objectives does not belong to this topic.'
                                );
                            }

                            $objective->update($attributes);
                        } else {
                            $topic->objectives()->create($attributes);
                        }
                    }
                }

                $curriculumAnalysis->update([
                    'status' => 'ready',
                ]);
            });

            return response()->json([
                'message' => 'Curriculum organization saved successfully.',

                'data' => $this->withFlags(
                    $curriculumAnalysis->fresh([
                        'courseMaterial:id,title,unit_id,subject_id,teacher_id',
                        'courseMaterial.supportingSources:id,course_material_id,title,url,domain,status,cached_title,content_type,validated_at,last_error',
                        'unit:id,name',
                        'topics.objectives',
                    ])
                ),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'Failed to save curriculum organization.');
        }
    }

    /**
     * Approve and publish a curriculum analysis.
     *
     * Version 1:
     *     - publishes its topics.
     *
     * Version 2:
     *     - archives Version 1.
     *     - archives Version 1 official topics/objectives.
     *     - publishes Version 2.
     *
     * Only two versions are allowed.
     *
     * A topic whose name already exists as an active topic in the unit is
     * reported as a conflict (HTTP 409 + "conflicts"). Re-send the request
     * with merge_existing=true to add the new objectives to the existing
     * topic instead.
     */
    public function approve(
        CurriculumAnalysis $curriculumAnalysis,
        KnowledgeChunkingService $chunkingService
    ): JsonResponse {
        $user = Auth::user();

        $this->authorizeAnalysis($curriculumAnalysis);

        // Supplements never publish AI-generated curriculum structures.
        abort_if($curriculumAnalysis->courseMaterial?->purpose === 'supplement', 422,
            'This material supports existing objectives. Use Approve Supplement instead.');

        /*
         * Already approved.
         *
         * Older approval flows could publish the curriculum analysis without
         * finalizing the linked course material or creating Tutor knowledge
         * chunks. Repair that state here instead of returning early.
         */
        if ($curriculumAnalysis->status === 'approved') {
            try {
                $chunkCount = $this->ensureTutorMaterialReady(
                    $curriculumAnalysis,
                    $user,
                    $chunkingService
                );

                return response()->json([
                    'message' =>
                        'This curriculum organization is already approved. The linked learning material is ready for Tutor use.',

                    'knowledge_chunks' => $chunkCount,

                    'data' => $this->withFlags(
                        $curriculumAnalysis->fresh()->load([
                            'courseMaterial:id,title,unit_id,subject_id,teacher_id,status,approved_by,approved_at',
                            'courseMaterial.supportingSources:id,course_material_id,title,url,domain,status,cached_title,content_type,validated_at,last_error',
                            'unit:id,name',
                            'topics.objectives',
                        ])
                    ),
                ]);
            } catch (Throwable $e) {
                return $this->failure(
                    $e,
                    'The curriculum organization is approved, but the linked learning material could not be prepared for Tutor use.'
                );
            }
        }

        /*
         * Only ready analyses can be approved.
         */
        if ($curriculumAnalysis->status !== 'ready') {
            return response()->json([
                'message' =>
                    'Only a ready curriculum organization can be approved.',
            ], 422);
        }

        $curriculumAnalysis->load([
            'courseMaterial',
            'unit.gradeSubject',
            'topics.objectives',
        ]);

        /*
         * Validate required relationships.
         */
        if (!$curriculumAnalysis->courseMaterial) {
            return response()->json([
                'message' =>
                    'The curriculum analysis is not linked to a valid course material.',
            ], 422);
        }

        if (!$curriculumAnalysis->unit) {
            return response()->json([
                'message' =>
                    'The curriculum analysis is not linked to a valid unit.',
            ], 422);
        }

        if (!$curriculumAnalysis->unit->grade_subject_id) {
            return response()->json([
                'message' =>
                    'The selected unit is not linked to a valid teaching area.',
            ], 422);
        }

        if (!$curriculumAnalysis->unit->gradeSubject) {
            return response()->json([
                'message' =>
                    'The selected unit is not linked to a valid teaching area.',
            ], 422);
        }

        if ($curriculumAnalysis->topics->isEmpty()) {
            return response()->json([
                'message' =>
                    'There are no proposed topics to approve.',
            ], 422);
        }

        $mergeExisting = request()->boolean('merge_existing');

        try {
            // Two topics with the same name in one analysis would make the
            // second collide with the first during publishing.
            $this->assertUniqueTopicNames(
                $curriculumAnalysis->topics->pluck('name')->all()
            );

            DB::transaction(function () use (
                $curriculumAnalysis,
                $user,
                $chunkingService,
                $mergeExisting
            ) {
                /*
                 * Serialize concurrent approvals (double click, two tabs):
                 * re-read the analysis under a row lock and re-check state.
                 */
                $locked = CurriculumAnalysis::query()
                    ->whereKey($curriculumAnalysis->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked || $locked->status !== 'ready') {
                    throw new CurriculumException(
                        'This curriculum organization has already been approved or is no longer ready.'
                    );
                }

                $gradeSubjectId = $curriculumAnalysis->unit->grade_subject_id;

                /*
                 * -----------------------------------------------------
                 * VERSION 2
                 * -----------------------------------------------------
                 *
                 * If this analysis has a parent, it is the revision
                 * of Version 1.
                 */
                if ($curriculumAnalysis->parent_analysis_id !== null) {
                    $parentAnalysis = CurriculumAnalysis::query()
                        ->with('topics')
                        ->find(
                            $curriculumAnalysis->parent_analysis_id
                        );

                    if (!$parentAnalysis) {
                        throw new CurriculumException(
                            'The previous curriculum analysis could not be found.'
                        );
                    }

                    /*
                     * A revision may be created from either:
                     *
                     * - a READY parent that was reviewed but never published; or
                     * - an APPROVED parent that is currently published.
                     *
                     * V2 must therefore be approvable directly.
                     */
                    if (!in_array(
                        $parentAnalysis->status,
                        ['ready', 'approved'],
                        true
                    )) {
                        throw new CurriculumException(
                            'The previous curriculum analysis is not in a state that can be replaced.'
                        );
                    }

                    /*
                     * Archive official topics only when the parent was
                     * actually published. A READY parent never created
                     * official Topic / LearningObjective records.
                     */
                    if ($parentAnalysis->status === 'approved') {
                        $previousTopics = Topic::query()
                            ->where('curriculum_analysis_id', $parentAnalysis->id)
                            ->where('unit_id', $curriculumAnalysis->unit_id)
                            ->where('grade_subject_id', $gradeSubjectId)
                            ->where('status', 'active')
                            ->get();

                        foreach ($previousTopics as $previousTopic) {
                            // Needs 'archived' in learning_objectives.status
                            // (see the 2026_11_26 migration).
                            $previousTopic
                                ->learningObjectives()
                                ->update(['status' => 'archived']);

                            $previousTopic->update(['status' => 'archived']);
                        }
                    }

                    /*
                     * The old analysis is superseded whether it was merely
                     * READY or already APPROVED.
                     */
                    $parentAnalysis->update(['status' => 'archived']);
                }

                /*
                 * -----------------------------------------------------
                 * CONFLICT CHECK
                 * -----------------------------------------------------
                 *
                 * Run after the parent was archived (its topics no longer
                 * count) and BEFORE anything is created, and report every
                 * conflicting name at once instead of failing on the first.
                 */
                $existingByName = [];
                $conflicts = [];

                foreach ($curriculumAnalysis->topics as $proposedTopic) {
                    $existing = Topic::query()
                        ->where('grade_subject_id', $gradeSubjectId)
                        ->where('unit_id', $curriculumAnalysis->unit_id)
                        ->where('status', 'active')
                        ->whereRaw(
                            'LOWER(TRIM(topic_name)) = ?',
                            [mb_strtolower(trim($proposedTopic->name))]
                        )
                        ->first();

                    if ($existing) {
                        $existingByName[$proposedTopic->id] = $existing;
                        $conflicts[] = $proposedTopic->name;
                    }
                }

                if ($conflicts && !$mergeExisting) {
                    throw (new CurriculumException(
                        count($conflicts) === 1
                            ? "The topic '{$conflicts[0]}' already exists as an active topic in this unit."
                            : count($conflicts) . ' topics already exist as active topics in this unit: ' .
                                implode(', ', $conflicts) . '.'
                    ))->withContext(['conflicts' => $conflicts]);
                }

                /*
                 * -----------------------------------------------------
                 * PUBLISH CURRENT ANALYSIS
                 * -----------------------------------------------------
                 */
                foreach (
                    $curriculumAnalysis
                        ->topics
                        ->sortBy('order')
                    as $proposedTopic
                ) {
                    if (isset($existingByName[$proposedTopic->id])) {
                        // The teacher chose to merge: add only objectives the
                        // existing topic does not already have.
                        $this->mergeIntoExistingTopic(
                            $existingByName[$proposedTopic->id],
                            $proposedTopic,
                            $user
                        );
                    } else {
                        /*
                         * Create official Topic, linked directly to the
                         * analysis that published it.
                         */
                        $topic = Topic::create([
                            'grade_subject_id' => $gradeSubjectId,
                            'unit_id' => $curriculumAnalysis->unit_id,
                            'curriculum_analysis_id' => $curriculumAnalysis->id,
                            'topic_name' => $proposedTopic->name,
                            'order' => $proposedTopic->order,
                            'status' => 'active',
                            'created_by' => $user->id,
                        ]);

                        foreach (
                            $proposedTopic
                                ->objectives
                                ->sortBy('order')
                            as $proposedObjective
                        ) {
                            LearningObjective::create([
                                'topic_id' => $topic->id,
                                'code' => $proposedObjective->code,
                                'objective' => $proposedObjective->objective,
                                'description' => $proposedObjective->description,
                                'order' => $proposedObjective->order,
                                'status' => 'active',
                                'created_by' => $user->id,
                            ]);
                        }
                    }

                    /*
                     * Mark AI analysis topic as approved.
                     */
                    $proposedTopic->update(['status' => 'approved']);

                    $proposedTopic
                        ->objectives()
                        ->update(['status' => 'approved']);
                }

                /*
                 * Publish the current analysis.
                 */
                $curriculumAnalysis->update([
                    'status' => 'approved',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                ]);

                /*
                 * Finalize the linked course material in the same approval
                 * workflow so Tutor can immediately retrieve grounded content.
                 */
                $this->ensureTutorMaterialReady(
                    $curriculumAnalysis,
                    $user,
                    $chunkingService
                );
            });

            $material = $curriculumAnalysis->courseMaterial()->first();
            $chunkCount = $material
                ? $material->knowledgeChunks()->where('status', 'ready')->count()
                : 0;

            return response()->json([
                'message' =>
                    'Curriculum organization approved successfully and the linked learning material is ready for Tutor use.',

                'knowledge_chunks' => $chunkCount,

                'data' => $this->withFlags(
                    $curriculumAnalysis->fresh([
                        'courseMaterial:id,title,unit_id,subject_id,teacher_id,status,approved_by,approved_at',
                        'courseMaterial.supportingSources:id,course_material_id,title,url,domain,status,cached_title,content_type,validated_at,last_error',
                        'unit:id,name',
                        'topics.objectives',
                    ])
                ),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'Failed to approve curriculum organization.');
        }
    }

    /**
     * What approving this revision would do to work built on the version it
     * replaces. Approving V2 archives V1's official objectives, so questions,
     * tutor sessions and support threads linked to them stay on archived
     * objectives. The review screen shows these counts before approval.
     */
    public function approvalImpact(
        CurriculumAnalysis $curriculumAnalysis
    ): JsonResponse {
        $this->authorizeAnalysis($curriculumAnalysis);

        $empty = [
            'replaces_published' => false,
            'topics' => 0,
            'objectives' => 0,
            'questions' => 0,
            'tutor_sessions' => 0,
            'support_threads' => 0,
        ];

        $parent = $curriculumAnalysis->parent_analysis_id
            ? CurriculumAnalysis::find($curriculumAnalysis->parent_analysis_id)
            : null;

        if (
            $curriculumAnalysis->status !== 'ready' ||
            !$parent ||
            $parent->status !== 'approved'
        ) {
            return response()->json(['data' => $empty]);
        }

        $topicIds = Topic::query()
            ->where('curriculum_analysis_id', $parent->id)
            ->where('unit_id', $curriculumAnalysis->unit_id)
            ->where('status', 'active')
            ->pluck('id');

        $objectiveIds = LearningObjective::query()
            ->whereIn('topic_id', $topicIds)
            ->where('status', 'active')
            ->pluck('id');

        $questions = 0;

        if (Schema::hasTable('questions')) {
            $questions = DB::table('questions')
                ->where(function ($query) use ($topicIds, $objectiveIds) {
                    $query->whereIn('topic_id', $topicIds)
                        ->orWhereIn('learning_objective_id', $objectiveIds);
                })
                ->count();
        }

        $tutorSessions = Schema::hasTable('tutor_session_objectives')
            ? DB::table('tutor_session_objectives')
                ->whereIn('learning_objective_id', $objectiveIds)
                ->distinct()
                ->count('tutor_session_id')
            : 0;

        $threads = Schema::hasTable('learning_support_threads')
            ? DB::table('learning_support_threads')
                ->whereIn('learning_objective_id', $objectiveIds)
                ->count()
            : 0;

        return response()->json([
            'data' => [
                'replaces_published' => true,
                'topics' => $topicIds->count(),
                'objectives' => $objectiveIds->count(),
                'questions' => $questions,
                'tutor_sessions' => $tutorSessions,
                'support_threads' => $threads,
            ],
        ]);
    }

    /**
     * Add a proposed topic's objectives to an existing active topic,
     * skipping objectives it already has.
     */
    private function mergeIntoExistingTopic(
        Topic $existingTopic,
        $proposedTopic,
        $user
    ): void {
        $known = $existingTopic
            ->learningObjectives()
            ->where('status', 'active')
            ->pluck('objective')
            ->map(fn ($text) => TextTerms::normalise((string) $text))
            ->flip()
            ->all();

        $nextOrder = (int) LearningObjective::query()
            ->where('topic_id', $existingTopic->id)
            ->max('order');

        foreach ($proposedTopic->objectives->sortBy('order') as $proposedObjective) {
            $key = TextTerms::normalise((string) $proposedObjective->objective);

            if (isset($known[$key])) {
                continue;
            }

            $known[$key] = true;

            LearningObjective::create([
                'topic_id' => $existingTopic->id,
                'code' => $proposedObjective->code,
                'objective' => $proposedObjective->objective,
                'description' => $proposedObjective->description,
                'order' => ++$nextOrder,
                'status' => 'active',
                'created_by' => $user->id,
            ]);
        }
    }

    /**
     * @param string[] $names
     * @throws CurriculumException
     */
    private function assertUniqueTopicNames(array $names): void
    {
        $seen = [];
        $duplicates = [];

        foreach ($names as $name) {
            $key = mb_strtolower(trim((string) $name));

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $duplicates[$key] = trim((string) $name);
            }

            $seen[$key] = true;
        }

        if ($duplicates) {
            throw (new CurriculumException(
                'Each topic in a unit needs a different name. Duplicate: ' .
                implode(', ', array_values($duplicates)) . '.'
            ))->withContext(['duplicate_topics' => array_values($duplicates)]);
        }
    }

    /**
     * Run the content-sufficiency gate on objectives the teacher added or
     * reworded in the review screen. Unchanged objectives are not re-checked.
     *
     * @param  array $topics validated "topics" payload
     * @return array<string,string> supporting passage per "topicIndex:objectiveIndex"
     * @throws CurriculumException when the material cannot teach an objective
     */
    private function verifyEditedObjectives(
        CurriculumAnalysis $analysis,
        array $topics,
        ObjectiveContentSufficiencyService $sufficiency
    ): array {
        $analysis->loadMissing(['topics.objectives', 'courseMaterial']);

        $existing = [];

        foreach ($analysis->topics as $topic) {
            foreach ($topic->objectives as $objective) {
                $existing[(int) $objective->id] = $objective;
            }
        }

        $changed = [];

        foreach ($topics as $topicIndex => $topic) {
            foreach ($topic['objectives'] as $objectiveIndex => $objective) {
                $current = !empty($objective['id'])
                    ? ($existing[(int) $objective['id']] ?? null)
                    : null;

                $unchanged = $current
                    && TextTerms::normalise((string) $current->objective)
                        === TextTerms::normalise((string) $objective['objective'])
                    && TextTerms::normalise((string) $current->description)
                        === TextTerms::normalise((string) ($objective['description'] ?? ''));

                if (!$unchanged) {
                    $changed["{$topicIndex}:{$objectiveIndex}"] = [
                        'topic' => $topic['name'],
                        'objective' => $objective['objective'],
                        'description' => $objective['description'] ?? null,
                    ];
                }
            }
        }

        if (!$changed) {
            return [];
        }

        if (count($changed) > 40) {
            throw new CurriculumException(
                'Too many new or reworded objectives to verify at once. Save in smaller steps.'
            );
        }

        $sourceText = trim((string) $analysis->courseMaterial?->extracted_text);

        if ($sourceText === '') {
            throw new CurriculumException(
                'The linked material has no extracted text, so new objectives cannot be verified against it.'
            );
        }

        $index = $sufficiency->index($sourceText);
        $items = [];

        foreach ($changed as $key => $item) {
            $items[$key] = $item + [
                'evidence' => $sufficiency->evidenceFor(
                    $index,
                    $item['objective'],
                    $item['description']
                ),
            ];
        }

        $verdicts = $sufficiency->assess($items);

        $excerpts = [];
        $refused = [];

        foreach ($verdicts as $key => $verdict) {
            if ($verdict['sufficient']) {
                $firstPassage = explode("\n\n---\n\n", $items[$key]['evidence'])[0];
                $excerpts[$key] = mb_substr($firstPassage, 0, 1200);
                continue;
            }

            [$topicIndex, $objectiveIndex] = array_map('intval', explode(':', (string) $key));

            $refused[] = [
                'topic_index' => $topicIndex,
                'objective_index' => $objectiveIndex,
                'topic' => $items[$key]['topic'],
                'objective' => $items[$key]['objective'],
                'reason' => $verdict['reason'],
                'missing' => $verdict['missing'],
            ];
        }

        if ($refused) {
            throw (new CurriculumException(
                count($refused) === 1
                    ? 'One objective was not saved because the uploaded material does not contain enough content to teach it. Upload fuller material, reword it, or remove it.'
                    : count($refused) . ' objectives were not saved because the uploaded material does not contain enough content to teach them. Upload fuller material, reword them, or remove them.'
            ))->withContext(['insufficient' => $refused]);
        }

        return $excerpts;
    }

    /**
     * Whether a (non-failed) revision of this analysis already exists. Used
     * by the review screen to hide "Refine with AI".
     */
    private function withFlags(CurriculumAnalysis $analysis): CurriculumAnalysis
    {
        $analysis->setAttribute(
            'has_active_revision',
            CurriculumAnalysis::query()
                ->where('parent_analysis_id', $analysis->id)
                ->where('status', '!=', 'failed')
                ->exists()
        );

        return $analysis;
    }

    /**
     * Turn an exception into a JSON error response.
     *
     * Only CurriculumException messages reach the browser. Anything else
     * (SQL errors, provider errors, bugs) is reported to the log and replaced
     * by $fallbackMessage so internals are never leaked.
     */
    protected function failure(
        Throwable $e,
        string $fallbackMessage,
        int $status = 422
    ): JsonResponse {
        if ($e instanceof CurriculumException) {
            $context = $e->context();

            if (isset($context['conflicts'])) {
                $status = 409;
            }

            return response()->json(array_merge($context, [
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ]), $status);
        }

        report($e);

        return response()->json([
            'message' => $fallbackMessage,
            'error' => $fallbackMessage,
        ], $status);
    }

    /**
     * Ensure the material linked to an approved curriculum analysis is fully
     * usable by Tutor. This also repairs older records where curriculum
     * approval succeeded but knowledge chunks were never created.
     */
    private function ensureTutorMaterialReady(
        CurriculumAnalysis $curriculumAnalysis,
        $user,
        KnowledgeChunkingService $chunkingService
    ): int {
        $material = $curriculumAnalysis->courseMaterial()->first();

        if (!$material) {
            throw new CurriculumException(
                'The curriculum analysis is not linked to a valid course material.'
            );
        }

        if (
            $material->extraction_status !== 'ready' ||
            $material->visual_extraction_status !== 'ready'
        ) {
            throw new CurriculumException(
                'The linked course material has not completed text and visual extraction.'
            );
        }

        if (
            !$material->extracted_text ||
            trim((string) $material->extracted_text) === ''
        ) {
            throw new CurriculumException(
                'The linked course material does not contain extracted text.'
            );
        }

        $readyChunkCount = $material
            ->knowledgeChunks()
            ->where('status', 'ready')
            ->count();

        if ($readyChunkCount === 0) {
            $readyChunkCount = $chunkingService->process(
                $material->fresh()
            );
        }

        if ($readyChunkCount <= 0) {
            throw new CurriculumException(
                'No usable Tutor knowledge chunks could be created from the linked material.'
            );
        }

        $material->update([
            'status' => 'approved',
            'approved_by' => $user?->id,
            'approved_at' => $material->approved_at ?: now(),
            'extraction_error' => null,
            'visual_extraction_error' => null,
        ]);

        return $readyChunkCount;
    }

    /**
     * Refine a curriculum analysis using AI.
     *
     * Only V1 can be refined.
     * A maximum of two versions is allowed:
     *
     * V1 -> V2
     */
    public function refine(
        CurriculumAnalysis $curriculumAnalysis,
        CurriculumAnalyzerService $analyzer
    ): JsonResponse {
        $user = Auth::user();

        $this->authorizeAnalysis($curriculumAnalysis);

        /*
         * Only ready or approved analyses can be refined.
         */
        if (!in_array(
            $curriculumAnalysis->status,
            ['ready', 'approved'],
            true
        )) {
            return response()->json([
                'message' =>
                    'Only a ready or approved curriculum analysis can be refined.',
            ], 422);
        }

        /*
         * V2 cannot be refined again.
         */
        if ($curriculumAnalysis->parent_analysis_id !== null) {
            return response()->json([
                'message' =>
                    'This curriculum analysis has already been refined. Only two versions are allowed.',
            ], 422);
        }

        /*
         * Prevent multiple V2 revisions from V1.
         */
        $existingRevision = CurriculumAnalysis::query()
            ->where(
                'parent_analysis_id',
                $curriculumAnalysis->id
            )
            // A failed attempt produced nothing the teacher can use, so it
            // must not stop them from trying again.
            ->where('status', '!=', 'failed')
            ->exists();

        if ($existingRevision) {
            return response()->json([
                'message' =>
                    'A revised curriculum analysis already exists for this version.',
            ], 422);
        }

        $validator = Validator::make(
            request()->all(),
            [
                'instruction' => [
                    'required',
                    'string',
                    'min:3',
                    'max:2000',
                ],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $instruction = trim(
            $validator->validated()['instruction']
        );

        // Revision runs the AI proposal plus a content-verification pass.
        @set_time_limit(300);

        try {
            $curriculumAnalysis->loadMissing([
                'courseMaterial',
            ]);

            if (!$curriculumAnalysis->courseMaterial) {
                return response()->json([
                    'message' =>
                        'The curriculum analysis is not linked to a valid course material.',
                ], 422);
            }

            /*
             * Create Version 2.
             *
             * The original analysis remains untouched.
             */
            $analysis = $analyzer->analyze(
                $curriculumAnalysis->courseMaterial,
                (int) $user->id,
                $user->school_id !== null
                    ? (int) $user->school_id
                    : null,
                $instruction,
                (int) $curriculumAnalysis->id
            );

            return response()->json([
                'message' =>
                    'A revised curriculum analysis has been created.',

                'data' => $analysis,
            ], 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'Curriculum refinement failed.');
        }
    }

    /**
     * Authorize analysis of a course material.
     */
    protected function authorizeCourseMaterial(
        CourseMaterial $courseMaterial
    ): void {
        $user = Auth::user();

        abort_unless(
            $user,
            403,
            'You must be logged in to analyze this course material.'
        );

        $role = strtolower(
            trim((string) $user->role)
        );

        abort_unless(
            in_array(
                $role,
                ['teacher', 'admin'],
                true
            ),
            403,
            'Your account is not authorized to analyze course materials.'
        );

        /*
         * Admin:
         * Only access materials belonging to their school.
         *
         * Teacher:
         * Only access their assigned Teaching Area.
         */
        $courseMaterial->loadMissing([
            'unit.gradeSubject',
        ]);

        $unit = $courseMaterial->unit;
        $gradeSubject = $unit?->gradeSubject;

        abort_unless(
            $unit && $gradeSubject,
            403,
            'This course material is not linked to a valid teaching area.'
        );

        /*
         * School must always be explicitly present and match.
         */
        abort_unless(
            $user->school_id !== null &&
                $gradeSubject->school_id !== null &&
                (int) $gradeSubject->school_id ===
                    (int) $user->school_id,
            403,
            'You are not authorized to analyze material from this teaching area.'
        );

        /*
         * Admins can access Teaching Areas in their school.
         */
        if ($role === 'admin') {
            return;
        }

        /*
         * Teachers can only access their own Teaching Areas.
         */
        abort_unless(
            $gradeSubject->teacher_id !== null &&
                (int) $gradeSubject->teacher_id ===
                    (int) $user->id,
            403,
            'You are not authorized to analyze material from this teaching area.'
        );
    }

    /**
     * Authorize access to a curriculum analysis.
     *
     * Admin:
     *   Same school.
     *
     * Teacher:
     *   Same school + owns Teaching Area.
     */
    protected function authorizeAnalysis(
        CurriculumAnalysis $curriculumAnalysis
    ): void {
        $user = Auth::user();

        $role = strtolower(
            trim((string) ($user?->role ?? ''))
        );

        abort_unless(
            $user &&
                in_array(
                    $role,
                    ['teacher', 'admin'],
                    true
                ),
            403,
            'You are not authorized to access this curriculum analysis.'
        );

        $curriculumAnalysis->loadMissing([
            'courseMaterial.unit.gradeSubject',
        ]);

        $gradeSubject =
            $curriculumAnalysis
                ->courseMaterial
                ?->unit
                ?->gradeSubject;

        abort_unless(
            $gradeSubject,
            403,
            'This curriculum analysis is not linked to a valid teaching area.'
        );

        /*
         * School must always match.
         */
        abort_unless(
            $user->school_id !== null &&
                $gradeSubject->school_id !== null &&
                (int) $gradeSubject->school_id ===
                    (int) $user->school_id,
            403,
            'You are not authorized to access this teaching area.'
        );

        /*
         * Admins can access analyses within their school.
         */
        if ($role === 'admin') {
            return;
        }

        /*
         * Teachers can access only their own Teaching Areas.
         */
        abort_unless(
            $gradeSubject->teacher_id !== null &&
                (int) $gradeSubject->teacher_id ===
                    (int) $user->id,
            403,
            'You are not authorized to access this teaching area.'
        );
    }
}