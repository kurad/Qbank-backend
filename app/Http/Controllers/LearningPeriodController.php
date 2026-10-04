<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\LearningPeriodTopic;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LearningPeriodController extends Controller
{
    /**
     * List learning periods created for a group.
     */
    public function index(Request $request, Group $group)
    {
        $this->ensureCanManage($request, $group);

        $periods = LearningPeriod::query()
            ->where('group_id', $group->id)
            ->with($this->periodRelations())
            ->latest('start_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => $periods,
        ]);
    }

    /**
     * Create a draft learning period.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'group_id' => [
                'required',
                'integer',
                'exists:groups,id',
            ],
            'grade_subject_id' => [
                'required',
                'integer',
                'exists:grade_subjects,id',
            ],
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'start_date' => [
                'required',
                'date',
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],
        ]);

        $group = Group::findOrFail($data['group_id']);

        $gradeSubject = GradeSubject::findOrFail(
            $data['grade_subject_id']
        );

        $this->ensureCanManageSubject(
            $request,
            $group,
            $gradeSubject
        );

        $period = LearningPeriod::create([
            'group_id' => $group->id,
            'grade_subject_id' => $gradeSubject->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Learning period created successfully.',
            'data' => $period->load($this->periodRelations()),
        ], 201);
    }

    /**
     * Show a learning period.
     */
    public function show(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        return response()->json([
            'data' => $learningPeriod->load(
                $this->periodRelations()
            ),
        ]);
    }

    /**
     * Update a draft learning period.
     */
    public function update(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        abort_if(
            $learningPeriod->isPublished(),
            422,
            'Published learning periods cannot be modified.'
        );

        $data = $request->validate([
            'grade_subject_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:grade_subjects,id',
            ],
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'start_date' => [
                'sometimes',
                'required',
                'date',
            ],
            'end_date' => [
                'sometimes',
                'required',
                'date',
                'after_or_equal:start_date',
            ],
        ]);

        /*
         * If the subject is changed, make sure the new
         * subject is valid for this teacher/group.
         */
        if (isset($data['grade_subject_id'])) {
            $gradeSubject = GradeSubject::findOrFail(
                $data['grade_subject_id']
            );

            $this->ensureCanManageSubject(
                $request,
                $group,
                $gradeSubject
            );

            /*
             * Do not allow changing the subject while topics
             * already exist unless they are still compatible.
             */
            if (
                (int) $gradeSubject->id !==
                (int) $learningPeriod->grade_subject_id
            ) {
                $hasTopics = $learningPeriod
                    ->topics()
                    ->exists();

                abort_if(
                    $hasTopics,
                    422,
                    'Remove the existing topics before changing the subject.'
                );
            }
        }

        /*
         * Prevent an invalid date range when only one date
         * is being updated.
         */
        $startDate = $data['start_date']
            ?? $learningPeriod->start_date?->toDateString();

        $endDate = $data['end_date']
            ?? $learningPeriod->end_date?->toDateString();

        if ($startDate && $endDate) {
            abort_if(
                strtotime($endDate) < strtotime($startDate),
                422,
                'The end date must be on or after the start date.'
            );
        }

        $learningPeriod->update($data);

        return response()->json([
            'message' => 'Learning period updated successfully.',
            'data' => $learningPeriod
                ->fresh()
                ->load($this->periodRelations()),
        ]);
    }

    /**
     * List topics assigned to a learning period.
     */
    public function topics(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        $topics = $learningPeriod
            ->topics()
            ->with([
                'unit:id,name',
                'topic:id,topic_name',
            ])
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'data' => $topics,
        ]);
    }

    /**
     * Add a topic to a learning period.
     */
    public function addTopic(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        abort_if(
            $learningPeriod->isPublished(),
            422,
            'Published learning periods cannot be modified.'
        );

        $data = $request->validate([
            'topic_id' => [
                'required',
                'integer',
                'exists:topics,id',
            ],
            'display_order' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $topic = Topic::with('unit')
            ->findOrFail($data['topic_id']);

        abort_unless(
            $topic->unit,
            422,
            'The selected topic does not have a valid unit.'
        );

        /*
         * Topic must belong to the subject selected for the
         * learning period.
         */
        abort_unless(
            (int) $topic->grade_subject_id ===
                (int) $learningPeriod->grade_subject_id,
            422,
            'The selected topic does not belong to this subject.'
        );

        /*
         * Prevent duplicate topics.
         */
        $exists = $learningPeriod
            ->topics()
            ->where('topic_id', $topic->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'topic_id' => [
                    'This topic is already included in the learning period.',
                ],
            ]);
        }

        $nextOrder = (
            $learningPeriod
            ->topics()
            ->max('display_order') ?? 0
        ) + 1;

        $displayOrder = $data['display_order'] ?? $nextOrder;

        DB::transaction(function () use (
            $learningPeriod,
            $displayOrder
        ) {
            $learningPeriod
                ->topics()
                ->where('display_order', '>=', $displayOrder)
                ->increment('display_order');
        });

        $periodTopic = $learningPeriod
            ->topics()
            ->create([
                'unit_id' => $topic->unit_id,
                'topic_id' => $topic->id,
                'display_order' => $displayOrder,
            ]);

        return response()->json([
            'message' => 'Topic added to learning period.',
            'data' => $periodTopic->load([
                'unit:id,name',
                'topic:id,topic_name',
            ]),
        ], 201);
    }

    /**
     * Update a topic assignment.
     */
    public function updateTopic(
        Request $request,
        LearningPeriod $learningPeriod,
        LearningPeriodTopic $learningPeriodTopic
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        abort_if(
            $learningPeriod->isPublished(),
            422,
            'Published learning periods cannot be modified.'
        );

        abort_unless(
            (int) $learningPeriodTopic->learning_period_id ===
                (int) $learningPeriod->id,
            404,
            'This topic does not belong to the selected learning period.'
        );

        $data = $request->validate([
            'display_order' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $oldOrder = (int) $learningPeriodTopic->display_order;
        $newOrder = (int) $data['display_order'];

        /*
         * Do not allow an order greater than the number of
         * topics + 1.
         */
        $topicCount = $learningPeriod
            ->topics()
            ->count();

        $newOrder = min(
            $newOrder,
            max(1, $topicCount)
        );

        if ($oldOrder !== $newOrder) {
            DB::transaction(function () use (
                $learningPeriod,
                $learningPeriodTopic,
                $oldOrder,
                $newOrder
            ) {
                if ($newOrder < $oldOrder) {
                    $learningPeriod
                        ->topics()
                        ->where('id', '!=', $learningPeriodTopic->id)
                        ->whereBetween(
                            'display_order',
                            [$newOrder, $oldOrder - 1]
                        )
                        ->increment('display_order');
                } else {
                    $learningPeriod
                        ->topics()
                        ->where('id', '!=', $learningPeriodTopic->id)
                        ->whereBetween(
                            'display_order',
                            [$oldOrder + 1, $newOrder]
                        )
                        ->decrement('display_order');
                }

                $learningPeriodTopic->update([
                    'display_order' => $newOrder,
                ]);
            });
        }

        return response()->json([
            'message' => 'Learning period topic updated successfully.',
            'data' => $learningPeriodTopic
                ->fresh()
                ->load([
                    'unit:id,name',
                    'topic:id,topic_name',
                ]),
        ]);
    }

    /**
     * Remove a topic from a draft learning period.
     */
    public function removeTopic(
        Request $request,
        LearningPeriod $learningPeriod,
        LearningPeriodTopic $learningPeriodTopic
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        abort_if(
            $learningPeriod->isPublished(),
            422,
            'Published learning periods cannot be modified.'
        );

        abort_unless(
            (int) $learningPeriodTopic->learning_period_id ===
                (int) $learningPeriod->id,
            404,
            'This topic does not belong to the selected learning period.'
        );

        $removedOrder = (int) $learningPeriodTopic->display_order;

        DB::transaction(function () use (
            $learningPeriod,
            $learningPeriodTopic,
            $removedOrder
        ) {
            $learningPeriodTopic->delete();

            $learningPeriod
                ->topics()
                ->where('display_order', '>', $removedOrder)
                ->decrement('display_order');
        });

        return response()->json([
            'message' => 'Topic removed from learning period.',
        ]);
    }

    /**
     * Publish learning period.
     */
    public function publish(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        abort_if(
            $learningPeriod->isPublished(),
            422,
            'This learning period is already published.'
        );

        $topicCount = $learningPeriod
            ->topics()
            ->count();

        if ($topicCount === 0) {
            throw ValidationException::withMessages([
                'topics' => [
                    'Add at least one topic before publishing the learning period.',
                ],
            ]);
        }

        /*
         * Make sure every assigned topic belongs to the
         * learning period subject.
         */
        $invalidTopicExists = $learningPeriod
            ->topics()
            ->whereHas('topic', function ($query) use ($learningPeriod) {
                $query->where(
                    'grade_subject_id',
                    '!=',
                    $learningPeriod->grade_subject_id
                );
            })
            ->exists();

        if ($invalidTopicExists) {
            throw ValidationException::withMessages([
                'topics' => [
                    'One or more assigned topics do not belong to the selected subject.',
                ],
            ]);
        }

        /*
         * Validate dates.
         */
        abort_if(
            !$learningPeriod->start_date ||
                !$learningPeriod->end_date,
            422,
            'A start date and end date are required before publishing.'
        );

        abort_if(
            $learningPeriod->end_date->lt(
                $learningPeriod->start_date
            ),
            422,
            'The end date must be on or after the start date.'
        );

        DB::transaction(function () use ($learningPeriod) {
            $learningPeriod->update([
                'status' => 'published',
                'published_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Learning period published successfully.',
            'data' => $learningPeriod
                ->fresh()
                ->load($this->periodRelations()),
        ]);
    }

    /**
     * Unpublish learning period.
     */
    public function unpublish(
        Request $request,
        LearningPeriod $learningPeriod
    ) {
        $group = $this->resolveGroup($learningPeriod);

        $this->ensureCanManage($request, $group);

        abort_unless(
            $learningPeriod->isPublished(),
            422,
            'This learning period is not currently published.'
        );

        $learningPeriod->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        return response()->json([
            'message' => 'Learning period unpublished successfully.',
            'data' => $learningPeriod
                ->fresh()
                ->load($this->periodRelations()),
        ]);
    }

    /**
     * Resolve the group belonging to a learning period.
     */
    protected function resolveGroup(
        LearningPeriod $learningPeriod
    ): Group {
        $group = $learningPeriod->group;

        abort_unless(
            $group,
            404,
            'The class associated with this learning period was not found.'
        );

        return $group;
    }

    protected function ensureCanManage(
        Request $request,
        Group $group,
        ?GradeSubject $gradeSubject = null
    ): void {
        $user = $request->user();

        abort_unless(
            $user,
            401,
            'Unauthenticated.'
        );

        /*
     * Group creator can manage the group's learning periods.
     */
        if ((int) $group->created_by === (int) $user->id) {
            return;
        }

        /*
     * Only teachers can manage learning periods.
     */
        abort_unless(
            $user->role === 'teacher',
            403,
            'You are not authorized to manage learning periods for this class.'
        );

        /*
     * If a specific subject is supplied, verify that the teacher
     * is assigned to that GradeSubject.
     */
        if ($gradeSubject) {
            abort_unless(
                (int) $gradeSubject->teacher_id === (int) $user->id,
                403,
                'You are not assigned to the selected subject.'
            );

            abort_unless(
                (int) $gradeSubject->school_id === (int) $user->school_id,
                403,
                'The selected subject is not available in your school.'
            );
        }
    }
    protected function ensureCanManageSubject(
        Request $request,
        Group $group,
        GradeSubject $gradeSubject
    ): void {
        $user = $request->user();

        abort_unless(
            $user,
            401,
            'Unauthenticated.'
        );

        /*
         * GradeSubject must belong to the teacher's school.
         */
        abort_unless(
            (int) $gradeSubject->school_id ===
                (int) $user->school_id,
            403,
            'The selected subject is not available in your school.'
        );

        /*
         * Group creator can assign any subject from their school
         * to their class.
         */
        if (
            (int) $group->created_by ===
            (int) $user->id
        ) {
            return;
        }

        /*
         * Otherwise the teacher must actually teach this
         * GradeSubject.
         */
        $isAssignedTeacher = GradeSubject::query()
            ->whereKey($gradeSubject->id)
            ->where('teacher_id', $user->id)
            ->exists();

        abort_unless(
            $isAssignedTeacher,
            403,
            'You are not assigned to the selected subject.'
        );
    }

    /**
     * Common relationships returned by the API.
     */
    protected function periodRelations(): array
    {
        return [
            'group:id,group_name,class_code',
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,grade_name',
            'topics' => function ($query) {
                $query
                    ->orderBy('display_order')
                    ->with([
                        'unit:id,name',
                        'topic:id,topic_name',
                    ]);
            },
        ];
    }
}
