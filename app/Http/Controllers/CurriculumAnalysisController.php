<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterial;
use App\Models\CurriculumAnalysis;
use App\Models\LearningObjective;
use App\Models\Topic;
use App\Services\CurriculumAnalyzerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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
            report($e);

            return response()->json([
                'message' => 'Curriculum analysis failed.',
                'error' => $e->getMessage(),
            ], 422);
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
            'data' => $curriculumAnalysis->load([
                'courseMaterial:id,title,unit_id,subject_id',
                'unit:id,name',
                'topics.objectives',
            ]),
        ]);
    }

    /**
     * Save teacher edits to a ready analysis.
     */
    public function update(
        CurriculumAnalysis $curriculumAnalysis
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
                'topics' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'topics.*.id' => [
                    'nullable',
                    'integer',
                ],

                'topics.*.name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'topics.*.description' => [
                    'nullable',
                    'string',
                ],

                'topics.*.order' => [
                    'required',
                    'integer',
                    'min:1',
                ],

                'topics.*.objectives' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'topics.*.objectives.*.id' => [
                    'nullable',
                    'integer',
                ],

                'topics.*.objectives.*.code' => [
                    'nullable',
                    'string',
                    'max:100',
                ],

                'topics.*.objectives.*.objective' => [
                    'required',
                    'string',
                ],

                'topics.*.objectives.*.description' => [
                    'nullable',
                    'string',
                ],

                'topics.*.objectives.*.order' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        try {
            DB::transaction(function () use (
                $curriculumAnalysis,
                $data
            ) {
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

                foreach ($data['topics'] as $topicData) {
                    /*
                     * Update an existing proposed topic.
                     */
                    if (!empty($topicData['id'])) {
                        $topic = $curriculumAnalysis
                            ->topics()
                            ->where('id', $topicData['id'])
                            ->first();

                        if (!$topic) {
                            throw new RuntimeException(
                                'One of the selected topics does not belong to this curriculum analysis.'
                            );
                        }

                        $topic->update([
                            'name' => $topicData['name'],
                            'description' =>
                                $topicData['description'] ?? null,
                            'order' => $topicData['order'],
                            'status' => 'proposed',
                        ]);
                    } else {
                        /*
                         * Create a new proposed topic.
                         */
                        $topic = $curriculumAnalysis
                            ->topics()
                            ->create([
                                'name' => $topicData['name'],
                                'description' =>
                                    $topicData['description'] ?? null,
                                'order' => $topicData['order'],
                                'status' => 'proposed',
                            ]);
                    }

                    /*
                     * Remove objectives that are no longer submitted.
                     */
                    $submittedObjectiveIds = collect(
                        $topicData['objectives']
                    )
                        ->pluck('id')
                        ->filter()
                        ->map(fn ($id) => (int) $id)
                        ->values();

                    $topic
                        ->objectives()
                        ->whereNotIn(
                            'id',
                            $submittedObjectiveIds
                        )
                        ->delete();

                    foreach (
                        $topicData['objectives']
                        as $objectiveData
                    ) {
                        if (!empty($objectiveData['id'])) {
                            $objective = $topic
                                ->objectives()
                                ->where(
                                    'id',
                                    $objectiveData['id']
                                )
                                ->first();

                            if (!$objective) {
                                throw new RuntimeException(
                                    'One of the selected learning objectives does not belong to this topic.'
                                );
                            }

                            $objective->update([
                                'code' =>
                                    $objectiveData['code'] ?? null,

                                'objective' =>
                                    $objectiveData['objective'],

                                'description' =>
                                    $objectiveData['description'] ?? null,

                                'order' =>
                                    $objectiveData['order'],

                                'status' => 'proposed',
                            ]);
                        } else {
                            $topic
                                ->objectives()
                                ->create([
                                    'code' =>
                                        $objectiveData['code'] ?? null,

                                    'objective' =>
                                        $objectiveData['objective'],

                                    'description' =>
                                        $objectiveData['description'] ?? null,

                                    'order' =>
                                        $objectiveData['order'],

                                    'status' => 'proposed',
                                ]);
                        }
                    }
                }

                $curriculumAnalysis->update([
                    'status' => 'ready',
                ]);
            });

            return response()->json([
                'message' =>
                    'Curriculum organization saved successfully.',

                'data' =>
                    $curriculumAnalysis->fresh([
                        'courseMaterial:id,title,unit_id,subject_id',
                        'unit:id,name',
                        'topics.objectives',
                    ]),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' =>
                    'Failed to save curriculum organization.',

                'error' => $e->getMessage(),
            ], 422);
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
     */
    public function approve(
        CurriculumAnalysis $curriculumAnalysis
    ): JsonResponse {
        $user = Auth::user();

        $this->authorizeAnalysis($curriculumAnalysis);

        /*
         * Already approved.
         */
        if ($curriculumAnalysis->status === 'approved') {
            return response()->json([
                'message' =>
                    'This curriculum organization has already been approved.',

                'data' =>
                    $curriculumAnalysis->load([
                        'courseMaterial:id,title,unit_id,subject_id',
                        'unit:id,name',
                        'topics.objectives',
                    ]),
            ]);
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

        try {
            DB::transaction(function () use (
                $curriculumAnalysis,
                $user
            ) {
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
                        throw new RuntimeException(
                            'The previous curriculum analysis could not be found.'
                        );
                    }

                    /*
                     * A revision may be created from either:
                     *
                     * - a READY parent that was reviewed but never published; or
                     * - an APPROVED parent that is currently published.
                     *
                     * Requiring a READY parent to be approved first creates an
                     * impossible workflow: the teacher refines V1 precisely because
                     * V1 should not be published. V2 must therefore be approvable
                     * directly.
                     */
                    if (!in_array(
                        $parentAnalysis->status,
                        ['ready', 'approved'],
                        true
                    )) {
                        throw new RuntimeException(
                            'The previous curriculum analysis is not in a state that can be replaced.'
                        );
                    }

                    /*
                     * -------------------------------------------------
                     * Archive official topics only when the parent was
                     * actually published.
                     * -------------------------------------------------
                     *
                     * A READY parent has never created official Topic /
                     * LearningObjective records, so there is nothing to
                     * unpublish in that case.
                     */
                    if ($parentAnalysis->status === 'approved') {
                        $previousTopics = Topic::query()
                            ->where(
                                'curriculum_analysis_id',
                                $parentAnalysis->id
                            )
                            ->where(
                                'unit_id',
                                $curriculumAnalysis->unit_id
                            )
                            ->where(
                                'grade_subject_id',
                                $curriculumAnalysis
                                    ->unit
                                    ->grade_subject_id
                            )
                            ->where(
                                'status',
                                'active'
                            )
                            ->get();

                        foreach ($previousTopics as $previousTopic) {
                            /*
                             * Archive official learning objectives.
                             */
                            $previousTopic
                                ->learningObjectives()
                                ->update([
                                    'status' => 'archived',
                                ]);

                            /*
                             * Archive the official topic.
                             */
                            $previousTopic->update([
                                'status' => 'archived',
                            ]);
                        }
                    }

                    /*
                     * The old analysis is superseded whether it was merely
                     * READY or already APPROVED.
                     */
                    $parentAnalysis->update([
                        'status' => 'archived',
                    ]);
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
                    /*
                     * Do not allow duplicate active topics.
                     */
                    $existingActiveTopic = Topic::query()
                        ->where(
                            'grade_subject_id',
                            $curriculumAnalysis
                                ->unit
                                ->grade_subject_id
                        )
                        ->where(
                            'unit_id',
                            $curriculumAnalysis->unit_id
                        )
                        ->where(
                            'status',
                            'active'
                        )
                        ->whereRaw(
                            'LOWER(TRIM(topic_name)) = ?',
                            [
                                mb_strtolower(
                                    trim($proposedTopic->name)
                                ),
                            ]
                        )
                        ->first();

                    if ($existingActiveTopic) {
                        throw new RuntimeException(
                            "The topic '{$proposedTopic->name}' already exists as an active topic in this unit."
                        );
                    }

                    /*
                     * Create official Topic.
                     *
                     * IMPORTANT:
                     * Link it directly to the analysis that published it.
                     */
                    $topic = Topic::create([
                        'grade_subject_id' =>
                            $curriculumAnalysis
                                ->unit
                                ->grade_subject_id,

                        'unit_id' =>
                            $curriculumAnalysis->unit_id,

                        'curriculum_analysis_id' =>
                            $curriculumAnalysis->id,

                        'topic_name' =>
                            $proposedTopic->name,

                        'order' =>
                            $proposedTopic->order,

                        'status' =>
                            'active',

                        'created_by' =>
                            $user->id,
                    ]);

                    /*
                     * Create official Learning Objectives.
                     */
                    foreach (
                        $proposedTopic
                            ->objectives
                            ->sortBy('order')
                        as $proposedObjective
                    ) {
                        LearningObjective::create([
                            'topic_id' =>
                                $topic->id,

                            'code' =>
                                $proposedObjective->code,

                            'objective' =>
                                $proposedObjective->objective,

                            'description' =>
                                $proposedObjective->description,

                            'order' =>
                                $proposedObjective->order,

                            'status' =>
                                'active',

                            'created_by' =>
                                $user->id,
                        ]);
                    }

                    /*
                     * Mark AI analysis topic as approved.
                     */
                    $proposedTopic->update([
                        'status' => 'approved',
                    ]);

                    $proposedTopic
                        ->objectives()
                        ->update([
                            'status' => 'approved',
                        ]);
                }

                /*
                 * Publish the current analysis.
                 */
                $curriculumAnalysis->update([
                    'status' => 'approved',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                ]);
            });

            return response()->json([
                'message' =>
                    'Curriculum organization approved successfully.',

                'data' =>
                    $curriculumAnalysis->fresh([
                        'courseMaterial:id,title,unit_id,subject_id',
                        'unit:id,name',
                        'topics.objectives',
                    ]),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' =>
                    'Failed to approve curriculum organization.',

                'error' => $e->getMessage(),
            ], 422);
        }
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
            report($e);

            return response()->json([
                'message' =>
                    'Curriculum refinement failed.',

                'error' => $e->getMessage(),
            ], 422);
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