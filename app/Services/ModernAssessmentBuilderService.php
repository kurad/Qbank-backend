<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\LearningObjective;
use App\Models\Question;
use App\Models\Topic;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModernAssessmentBuilderService
{
    /**
     * Create a new assessment draft.
     */
    public function createDraft(
        int $creatorId,
        array $data
    ): Assessment {
        return Assessment::create([
            'title' => $data['title'],

            'type' =>
            $data['type'] ??
                'quiz',

            'creator_id' =>
            $creatorId,

            'question_count' =>
            0,

            'delivery_mode' =>
            $data['delivery_mode'] ??
                'online',

            'due_date' =>
            null,

            'is_timed' =>
            (bool) (
                $data['is_timed'] ??
                false
            ),

            'time_limit' =>
            $data['time_limit'] ??
                null,

            'instructions' =>
            $data['instructions'] ??
                null,

            'status' =>
            'draft',
        ]);
    }

    /**
     * Return everything required by the builder.
     */
    public function getForBuilder(
        Assessment $assessment
    ): Assessment {
        return $assessment->load([
            'units.gradeSubject.gradeLevel',
            'units.gradeSubject.subject',

            'topics.unit',
            'topics.learningObjectives',

            'learningObjectives.topic.unit',

            'assessmentQuestions.question.topic.unit',
            'assessmentQuestions.question.learningObjective',
        ]);
    }

    /**
     * Update basic assessment information.
     */
    public function updateAssessment(
        Assessment $assessment,
        int $userId,
        array $data
    ): Assessment {
        $this->ensureDraftOwner(
            $assessment,
            $userId
        );

        $isTimed =
            (bool) (
                $data['is_timed'] ??
                false
            );

        $assessment->update([
            'title' =>
            $data['title'],

            'type' =>
            $data['type'] ??
                'quiz',

            'delivery_mode' =>
            $data['delivery_mode'] ??
                'online',

            'is_timed' =>
            $isTimed,

            'time_limit' =>
            $isTimed
                ? (
                    $data['time_limit'] ??
                    null
                )
                : null,

            'instructions' =>
            $data['instructions'] ??
                null,
        ]);

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    /**
     * Update assessment blueprint.
     */
    public function updateBlueprint(
        Assessment $assessment,
        int $userId,
        array $data
    ): Assessment {
        $this->ensureDraftOwner(
            $assessment,
            $userId
        );

        $mode =
            $data['mode'] ??
            'manual';

        $totalQuestions =
            (int) (
                $data['total_questions'] ??
                0
            );

        if ($totalQuestions < 1) {
            throw ValidationException::withMessages([
                'total_questions' =>
                'The assessment must contain at least one question.',
            ]);
        }

        $bloomDistribution = [
            'remembering' =>
            (int) (
                $data['bloom_distribution']['remembering'] ??
                0
            ),

            'understanding' =>
            (int) (
                $data['bloom_distribution']['understanding'] ??
                0
            ),

            'applying' =>
            (int) (
                $data['bloom_distribution']['applying'] ??
                0
            ),

            'analyzing' =>
            (int) (
                $data['bloom_distribution']['analyzing'] ??
                0
            ),

            'evaluating' =>
            (int) (
                $data['bloom_distribution']['evaluating'] ??
                0
            ),

            'creating' =>
            (int) (
                $data['bloom_distribution']['creating'] ??
                0
            ),
        ];

        if ($mode === 'blueprint') {
            $bloomTotal =
                array_sum(
                    $bloomDistribution
                );

            if (
                $bloomTotal !==
                $totalQuestions
            ) {
                throw ValidationException::withMessages([
                    'bloom_distribution' =>
                    "Bloom's distribution must total {$totalQuestions} questions. Current total: {$bloomTotal}.",
                ]);
            }
        }

        $typeDistribution = null;

        if (
            isset(
                $data['type_distribution']
            )
        ) {
            $candidate = [
                'mcq' =>
                (int) (
                    $data['type_distribution']['mcq'] ??
                    0
                ),

                'true_false' =>
                (int) (
                    $data['type_distribution']['true_false'] ??
                    0
                ),

                'matching' =>
                (int) (
                    $data['type_distribution']['matching'] ??
                    0
                ),

                'short_answer' =>
                (int) (
                    $data['type_distribution']['short_answer'] ??
                    0
                ),

                'open_ended' =>
                (int) (
                    $data['type_distribution']['open_ended'] ??
                    0
                ),
            ];

            $typeTotal =
                array_sum(
                    $candidate
                );

            if ($typeTotal > 0) {
                if (
                    $typeTotal !==
                    $totalQuestions
                ) {
                    throw ValidationException::withMessages([
                        'type_distribution' =>
                        "Question type distribution must total {$totalQuestions} questions. Current total: {$typeTotal}.",
                    ]);
                }

                $typeDistribution =
                    $candidate;
            }
        }

        $assessment->update([
            'question_blueprint' => [
                'mode' =>
                $mode,

                'total_questions' =>
                $totalQuestions,

                'bloom_distribution' =>
                $bloomDistribution,

                'type_distribution' =>
                $typeDistribution,
            ],
        ]);

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    /**
     * Save assessment curriculum scope.
     *
     * Objectives are optional.
     *
     * Topics are the minimum meaningful scope.
     */
    public function setScope(
        Assessment $assessment,
        int $userId,
        array $unitIds,
        array $topicIds,
        array $objectiveIds
    ): Assessment {
        $this->ensureDraftOwner(
            $assessment,
            $userId
        );

        $unitIds =
            collect($unitIds)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $topicIds =
            collect($topicIds)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $objectiveIds =
            collect($objectiveIds)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (!count($unitIds)) {
            throw ValidationException::withMessages([
                'unit_ids' =>
                'At least one unit must be selected.',
            ]);
        }

        if (!count($topicIds)) {
            throw ValidationException::withMessages([
                'topic_ids' =>
                'At least one topic must be selected.',
            ]);
        }

        $units = Unit::query()
            ->with('gradeSubject')
            ->whereIn('id', $unitIds)
            ->get();

        $topics = Topic::query()
            ->with([
                'unit',
                'gradeSubject',
            ])
            ->whereIn('id', $topicIds)
            ->get();

        $objectives =
            count($objectiveIds)
            ? LearningObjective::query()
            ->with(
                'topic.gradeSubject'
            )
            ->whereIn(
                'id',
                $objectiveIds
            )
            ->get()
            : collect();

        $this->ensureCompleteIds(
            $unitIds,
            $units
                ->pluck('id')
                ->all(),
            'unit_ids'
        );

        $this->ensureCompleteIds(
            $topicIds,
            $topics
                ->pluck('id')
                ->all(),
            'topic_ids'
        );

        if (count($objectiveIds)) {
            $this->ensureCompleteIds(
                $objectiveIds,
                $objectives
                    ->pluck('id')
                    ->all(),
                'learning_objective_ids'
            );
        }

        $this->ensureCurriculumAccess(
            $units,
            $topics,
            $objectives,
            $userId
        );

        /*
         * One assessment = one Grade Subject / Teaching Area.
         */
        $gradeSubjectIds =
            $units
            ->map(
                fn($unit) =>
                $unit
                    ->gradeSubject
                    ?->id
            )
            ->filter()
            ->map(
                fn($id) =>
                (int) $id
            )
            ->unique()
            ->values();

        if (
            $gradeSubjectIds->count() !==
            1
        ) {
            throw ValidationException::withMessages([
                'unit_ids' =>
                'All selected units must belong to the same teaching area / grade subject.',
            ]);
        }

        $gradeSubjectId =
            (int) $gradeSubjectIds->first();

        /*
         * Topics must belong to selected units.
         */
        $unitIdSet =
            array_fill_keys(
                array_map(
                    'intval',
                    $unitIds
                ),
                true
            );

        foreach ($topics as $topic) {
            if (
                !$topic->unit_id ||
                !isset(
                    $unitIdSet[(int) $topic->unit_id]
                )
            ) {
                throw ValidationException::withMessages([
                    'topic_ids' =>
                    "Topic {$topic->id} must belong to one of the selected units.",
                ]);
            }

            if (
                !$topic->grade_subject_id ||
                (int) $topic->grade_subject_id !==
                $gradeSubjectId
            ) {
                throw ValidationException::withMessages([
                    'topic_ids' =>
                    "Topic {$topic->id} does not belong to the selected teaching area.",
                ]);
            }
        }

        /*
         * Objectives are optional.
         *
         * When supplied, each objective must belong to one
         * of the selected topics.
         */
        if (count($objectiveIds)) {
            $topicIdSet =
                array_fill_keys(
                    array_map(
                        'intval',
                        $topicIds
                    ),
                    true
                );

            foreach ($objectives as $objective) {
                if (
                    !$objective->topic_id ||
                    !isset(
                        $topicIdSet[(int) $objective->topic_id]
                    )
                ) {
                    throw ValidationException::withMessages([
                        'learning_objective_ids' =>
                        "Learning objective {$objective->id} must belong to one of the selected topics.",
                    ]);
                }

                $objectiveGradeSubjectId =
                    $objective
                    ->topic
                    ?->grade_subject_id;

                if (
                    !$objectiveGradeSubjectId ||
                    (int) $objectiveGradeSubjectId !==
                    $gradeSubjectId
                ) {
                    throw ValidationException::withMessages([
                        'learning_objective_ids' =>
                        "Learning objective {$objective->id} does not belong to the selected teaching area.",
                    ]);
                }
            }
        }

        DB::transaction(
            function () use (
                $assessment,
                $unitIds,
                $topicIds,
                $objectiveIds
            ) {
                $assessment
                    ->units()
                    ->sync($unitIds);

                $assessment
                    ->topics()
                    ->sync($topicIds);

                $assessment
                    ->learningObjectives()
                    ->sync($objectiveIds);
            }
        );

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    public function questionPool(
        int $userId,
        array $filters
    ) {
        $user = auth()->user();

        $query = Question::query()
            ->assessmentEligible()
            ->whereNull('parent_question_id')
            ->where(function (Builder $q) use (
                $userId,
                $user
            ) {
                $q->whereHas('topic.gradeSubject',function (
                        Builder $gradeSubject
                    ) use ($userId, $user) {
                        $gradeSubject->where('school_id', $user->school_id);

                        if ($user->role !== 'admin') {
                            $gradeSubject->where('teacher_id', $userId);
                        }
                    }
                );
                if ($user->role !== 'admin') {
                    $q->orWhere(function (Builder $createdQuestion) use (
                        $userId,
                        $user
                    ) {
                        $createdQuestion
                            ->where('created_by', $userId)
                            ->whereHas('topic.gradeSubject', function (
                                    Builder $gradeSubject
                                ) use ($user) {
                                    $gradeSubject->where('school_id', $user->school_id);
                                }
                            );
                    });
                }
            })

            ->with([
                'topic.unit',
                'topic.gradeSubject.gradeLevel',
                'topic.gradeSubject.subject',
                'learningObjective',
                'subQuestions.learningObjective',
            ]);

        if (!empty($filters['unit_ids'])) {
            $unitIds = collect($filters['unit_ids'])
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $query->whereHas('topic',function (Builder $q) use ($unitIds) {
                    $q->whereIn('unit_id',$unitIds);
                }
            );
        }

        if (!empty($filters['topic_ids'])) {
            $topicIds = collect($filters['topic_ids'])
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $query->whereIn('topic_id', $topicIds);
        }

        $objectiveFilter = $filters['objective_filter']?? 'all';
        $specificObjectiveId =$filters['learning_objective_id']?? null;

        if ($specificObjectiveId !== null && $specificObjectiveId !== '') {
            $query->where('learning_objective_id', (int) $specificObjectiveId);
        } elseif (
            $objectiveFilter === 'none'
        ) {
            $query->whereNull('learning_objective_id');
        } elseif ($objectiveFilter === 'assigned') {
            $query->whereNotNull('learning_objective_id');
        }

        if (!empty($filters['question_type'])) {
            $query->where('question_type',$filters['question_type']);
        }

        if (!empty($filters['difficulty_level'])) {
            $query->where('difficulty_level',$filters['difficulty_level']);
        }

        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $q) use ($search) {
                    $q->where('question_text', 'like', "%{$search}%")
                        ->orWhere('question', 'like', "%{$search}%");
                }
            );
        }

        if (isset($filters['min_marks'])) {
            $query->where('marks', '>=', $filters['min_marks']);
        }

        if (isset($filters['max_marks'])) {
            $query->where('marks', '<=', $filters['max_marks']);
        }

        // Deduplicate only after all access and filter constraints have been applied.
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        $questions = $query
            ->orderBy('topic_id')
            ->orderByRaw('learning_objective_id IS NULL DESC')
            ->orderBy('learning_objective_id')
            ->orderBy('id')
            ->get();

        $uniqueQuestions = $questions
            ->unique(function (Question $question) {
                return $question->question_fingerprint
                    ?: 'legacy-' . $question->id;
            })
            ->values();

        $page = max(1, (int) request()->input('page', 1));
        $total = $uniqueQuestions->count();
        $items = $uniqueQuestions->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    public function addQuestions(
        Assessment $assessment,
        int $userId,
        array $questionIds
    ): Assessment {
        $this->ensureDraftOwner($assessment, $userId);

        $questionIds = collect($questionIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $questions = Question::query()
            ->assessmentEligible()
            ->whereNull('parent_question_id')
            ->whereIn('id', $questionIds)
            ->get();

        $this->ensureCompleteIds(
            $questionIds,
            $questions
                ->pluck('id')
                ->all(),
            'question_ids'
        );

        $topicIds = $assessment->topics()
            ->pluck('topics.id')
            ->map(fn($id) => (int) $id)
            ->all();

        $objectiveIds = $assessment
            ->learningObjectives()
            ->pluck('learning_objectives.id')
            ->map(fn($id) => (int) $id)
            ->all();

        $fingerprintsInRequest = [];
        foreach ($questions as $question) {
            $fingerprint = $question->question_fingerprint
                ?: Question::buildQuestionFingerprint(
                    $question->topic_id,
                    $question->learning_objective_id,
                    $question->question_type,
                    $question->question
                );

            if (isset($fingerprintsInRequest[$fingerprint])) {
                throw ValidationException::withMessages([
                    'question_ids' => "Questions {$fingerprintsInRequest[$fingerprint]} and {$question->id} are duplicates. Only one can be added.",
                ]);
            }

            $fingerprintsInRequest[$fingerprint] = $question->id;
        }

        $existingAssessmentQuestions = $assessment->assessmentQuestions()
            ->with('question:id,topic_id,learning_objective_id,question_type,question,question_fingerprint')
            ->get();

        $existingQuestionIds = $existingAssessmentQuestions
            ->pluck('question_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $existingFingerprints = [];
        foreach ($existingAssessmentQuestions as $pivot) {
            $existingQuestion = $pivot->question;
            if (!$existingQuestion) {
                continue;
            }

            $fingerprint = $existingQuestion->question_fingerprint
                ?: Question::buildQuestionFingerprint(
                    $existingQuestion->topic_id,
                    $existingQuestion->learning_objective_id,
                    $existingQuestion->question_type,
                    $existingQuestion->question
                );

            $existingFingerprints[$fingerprint] = $existingQuestion->id;
        }

        foreach ($questions as $question) {
            if (in_array((int) $question->id, $existingQuestionIds, true)) {
                continue;
            }

            $fingerprint = $question->question_fingerprint
                ?: Question::buildQuestionFingerprint(
                    $question->topic_id,
                    $question->learning_objective_id,
                    $question->question_type,
                    $question->question
                );

            if (isset($existingFingerprints[$fingerprint])) {
                throw ValidationException::withMessages([
                    'question_ids' => "Question {$question->id} duplicates question {$existingFingerprints[$fingerprint]} already in this assessment.",
                ]);
            }
        }

        foreach (
            $questions as $question
        ) {
            if (!in_array((int) $question->topic_id, $topicIds, true)) {
                throw ValidationException::withMessages([
                    'question_ids' =>
                    "Question {$question->id} is outside the selected assessment topics.",
                ]);
            }

            if ($question->learning_objective_id && !in_array(
                    (int) $question
                        ->learning_objective_id, $objectiveIds, true
                )
            ) {
                throw ValidationException::withMessages([
                    'question_ids' => "Question {$question->id} is linked to an objective not selected for this assessment.",
                ]);
            }
        }

        DB::transaction(
            function () use (
                $assessment,
                $questions
            ) {
                $nextOrder =
                    (
                        (int)
                        $assessment
                            ->assessmentQuestions()
                            ->max('order')
                    ) + 1;

                foreach (
                    $questions as $question
                ) {
                    if (
                        $assessment
                        ->assessmentQuestions()
                        ->where(
                            'question_id',
                            $question->id
                        )
                        ->exists()
                    ) {
                        continue;
                    }

                    $assessment
                        ->assessmentQuestions()
                        ->create([
                            'question_id' =>
                            $question->id,

                            'order' =>
                            $nextOrder++,
                        ]);
                }

                $assessment->update([
                    'question_count' =>
                    $assessment
                        ->assessmentQuestions()
                        ->count(),
                ]);

                $assessment->refresh();
            }
        );

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    /**
     * Publish a completed assessment.
     *
     * Publishing is the author-side completion action for the modern
     * assessment builder. A published assessment is no longer editable
     * through the draft builder workflow.
     */
    public function publishAssessment(
        Assessment $assessment,
        int $userId
    ): Assessment {
        /*
         * Always start from fresh persisted state. This avoids publishing
         * against a stale Assessment instance or a previously loaded
         * relationship collection.
         */
        $assessment->refresh();

        $this->ensureDraftOwner(
            $assessment,
            $userId
        );

        /*
         * The authoritative source for selected questions is the
         * assessment_questions relationship, not Assessment.question_count.
         *
         * First count normal records whose related Question still exists.
         */
        $questionRelation = $assessment->assessmentQuestions();

        $questionCount = $questionRelation
            ->whereHas('question')
            ->count();

        /*
         * If the relationship has a global scope (for example SoftDeletes),
         * also check the underlying records without global scopes. This lets
         * us distinguish "no questions" from "questions hidden by a model
         * scope" instead of incorrectly rejecting a valid assessment.
         */
        if ($questionCount < 1) {
            $unscopedQuestionCount = $assessment
                ->assessmentQuestions()
                ->withoutGlobalScopes()
                ->whereHas('question')
                ->count();

            if ($unscopedQuestionCount > 0) {
                $questionCount = $unscopedQuestionCount;
            }
        }

        if ($questionCount < 1) {
            /*
             * Do not use question_count as a substitute for actual
             * assessment-question records. question_count is a cached
             * summary and may be stale.
             */
            throw ValidationException::withMessages([
                'questions' =>
                'The assessment has no persisted assessment-question records. Please return to Question Selection, confirm the selected questions are saved, and try publishing again.',
            ]);
        }

        $unitCount = $assessment->units()->count();
        $topicCount = $assessment->topics()->count();

        if ($unitCount < 1 || $topicCount < 1) {
            throw ValidationException::withMessages([
                'scope' =>
                'The assessment must have at least one unit and one topic selected before it can be published.',
            ]);
        }

        $blueprint = $assessment->question_blueprint;

        if (!is_array($blueprint) || empty($blueprint['mode'])) {
            throw ValidationException::withMessages([
                'question_blueprint' =>
                'The assessment blueprint must be configured before it can be published.',
            ]);
        }

        /*
         * Re-check the persisted question records inside the transaction
         * immediately before changing the assessment status.
         */
        DB::transaction(function () use ($assessment) {
            $persistedQuestionCount = $assessment
                ->assessmentQuestions()
                ->whereHas('question')
                ->count();

            if ($persistedQuestionCount < 1) {
                $persistedQuestionCount = $assessment
                    ->assessmentQuestions()
                    ->withoutGlobalScopes()
                    ->whereHas('question')
                    ->count();
            }

            if ($persistedQuestionCount < 1) {
                throw ValidationException::withMessages([
                    'questions' =>
                    'The assessment questions could not be confirmed while publishing. Please return to Question Selection and save the questions again.',
                ]);
            }

            $assessment->update([
                'status' => 'published',
                'question_count' => $persistedQuestionCount,
            ]);
        });

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    /**
     * Remove question.
     */
    public function removeQuestion(
        Assessment $assessment,
        int $userId,
        int $questionId
    ): Assessment {
        $this->ensureDraftOwner(
            $assessment,
            $userId
        );

        $assessment
            ->assessmentQuestions()
            ->where(
                'question_id',
                $questionId
            )
            ->delete();

        $this->renumberQuestions(
            $assessment
        );

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    /**
     * Reorder questions.
     */
    public function reorderQuestions(
        Assessment $assessment,
        int $userId,
        array $questionIds
    ): Assessment {
        $this->ensureDraftOwner(
            $assessment,
            $userId
        );

        $currentIds =
            $assessment
            ->assessmentQuestions()
            ->pluck('question_id')
            ->sort()
            ->values()
            ->all();

        $newIds =
            collect($questionIds)
            ->map(
                fn($id) =>
                (int) $id
            )
            ->sort()
            ->values()
            ->all();

        if (
            $currentIds !==
            $newIds
        ) {
            throw ValidationException::withMessages([
                'question_ids' =>
                'The supplied question order must contain exactly the questions currently in the assessment.',
            ]);
        }

        DB::transaction(
            function () use (
                $assessment,
                $questionIds
            ) {
                foreach (
                    $questionIds as
                    $index => $questionId
                ) {
                    $assessment
                        ->assessmentQuestions()
                        ->where(
                            'question_id',
                            $questionId
                        )
                        ->update([
                            'order' =>
                            $index + 1,
                        ]);
                }
            }
        );

        return $this->getForBuilder(
            $assessment->fresh()
        );
    }

    /**
     * Ensure assessment is editable by its creator.
     */
    protected function ensureDraftOwner(
        Assessment $assessment,
        int $userId
    ): void {
        if (
            (int) $assessment->creator_id !==
            $userId ||
            $assessment->status !==
            'draft'
        ) {
            throw ValidationException::withMessages([
                'assessment' =>
                'Only the teacher who owns a draft assessment can modify it.',
            ]);
        }
    }

    /**
     * Ensure requested IDs were all found.
     */
    protected function ensureCompleteIds(
        array $requested,
        array $found,
        string $field
    ): void {
        $requested =
            collect($requested)
            ->map(
                fn($id) =>
                (int) $id
            )
            ->unique()
            ->sort()
            ->values();

        $found =
            collect($found)
            ->map(
                fn($id) =>
                (int) $id
            )
            ->unique()
            ->sort()
            ->values();

        if (
            $requested->all() !==
            $found->all()
        ) {
            throw ValidationException::withMessages([
                $field =>
                'One or more selected records could not be found.',
            ]);
        }
    }

    /**
     * Verify curriculum access.
     */
    protected function ensureCurriculumAccess(
        $units,
        $topics,
        $objectives,
        int $userId
    ): void {
        $user =
            auth()->user();

        $schoolId =
            $user?->school_id;

        foreach (
            $units as $unit
        ) {
            $area =
                $unit->gradeSubject;

            if (
                !$area ||
                (int) $area->school_id !==
                (int) $schoolId ||
                (
                    $user->role !==
                    'admin' &&
                    (int) $area->teacher_id !==
                    $userId
                )
            ) {
                throw ValidationException::withMessages([
                    'unit_ids' =>
                    'One or more selected units are not available to you.',
                ]);
            }
        }

        foreach (
            $topics as $topic
        ) {
            $area =
                $topic->gradeSubject;

            if (
                !$area ||
                (int) $area->school_id !==
                (int) $schoolId ||
                (
                    $user->role !==
                    'admin' &&
                    (int) $area->teacher_id !==
                    $userId
                )
            ) {
                throw ValidationException::withMessages([
                    'topic_ids' =>
                    'One or more selected topics are not available to you.',
                ]);
            }
        }

        foreach (
            $objectives as $objective
        ) {
            $area =
                $objective
                ->topic
                ?->gradeSubject;

            if (
                !$area ||
                (int) $area->school_id !==
                (int) $schoolId ||
                (
                    $user->role !==
                    'admin' &&
                    (int) $area->teacher_id !==
                    $userId
                )
            ) {
                throw ValidationException::withMessages([
                    'learning_objective_ids' =>
                    'One or more selected learning objectives are not available to you.',
                ]);
            }
        }
    }

    /**
     * Renumber assessment questions.
     */
    protected function renumberQuestions(
        Assessment $assessment
    ): void {
        DB::transaction(
            function () use (
                $assessment
            ) {
                $questions =
                    $assessment
                    ->assessmentQuestions()
                    ->orderBy('order')
                    ->orderBy('id')
                    ->get();

                foreach (
                    $questions as
                    $index => $pivot
                ) {
                    $pivot->update([
                        'order' =>
                        $index + 1,
                    ]);
                }

                $assessment->update([
                    'question_count' =>
                    $questions->count(),
                ]);
            }
        );
    }
}