<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\Topic;
use App\Services\TutorContextService;
use Illuminate\Http\Request;

class StudentPracticeController extends Controller
{
    public function __construct(
        protected TutorContextService $context
    ) {
    }

    /**
     * Return only practice content that belongs to classes the
     * authenticated learner is enrolled in.
     *
     * Student practice context is:
     *
     * Class
     * -> Published Learning Period
     * -> Scheduled Topic
     * -> Approved assessment-eligible questions
     *
     * We intentionally do not use users.grade_level_id.
     */
    public function context(Request $request)
    {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can access practice.'
        );

        $groups = $this->context
            ->studentGroups($student);

        $today = now()->toDateString();

        $payload = $groups
            ->map(function ($group) use ($student, $today) {
                $model = Group::with([
                    'gradeSubject.subject:id,name',
                    'gradeSubject.gradeLevel:id,grade_name',
                ])->find($group->id);

                if (!$model) {
                    return null;
                }

                /*
                 * Practice may include current and previously-started
                 * published learning periods, but not future periods.
                 */
                $periods = $this->context
                    ->learningPeriodsForStudent(
                        $student,
                        $model,
                        false
                    )
                    ->filter(
                        fn (LearningPeriod $period) =>
                            $period->start_date
                                ->toDateString()
                            <= $today
                    )
                    ->values();

                $topicIds = $periods
                    ->flatMap(
                        fn (LearningPeriod $period) =>
                            $period->topics
                                ->pluck('topic_id')
                    )
                    ->filter()
                    ->unique()
                    ->values();

                $questionCounts = Question::query()
                    ->whereIn(
                        'topic_id',
                        $topicIds
                    )
                    ->whereNull(
                        'parent_question_id'
                    )
                    ->where(
                        'status',
                        'approved'
                    )
                    ->where(
                        'is_assessment_eligible',
                        true
                    )
                    ->selectRaw(
                        'topic_id, COUNT(*) as aggregate'
                    )
                    ->groupBy('topic_id')
                    ->pluck(
                        'aggregate',
                        'topic_id'
                    );

                $periodPayload = $periods
                    ->map(function (
                        LearningPeriod $period
                    ) use (
                        $questionCounts,
                        $today
                    ) {
                        $topics = $period
                            ->topics
                            ->map(function ($item) use (
                                $questionCounts
                            ) {
                                return [
                                    'id' =>
                                        $item->id,

                                    'unit_id' =>
                                        $item->unit_id,

                                    'unit_name' =>
                                        $item->unit?->name,

                                    'topic_id' =>
                                        $item->topic_id,

                                    'topic_name' =>
                                        $item
                                            ->topic
                                            ?->topic_name,

                                    'display_order' =>
                                        $item
                                            ->display_order,

                                    'question_count' =>
                                        (int) (
                                            $questionCounts[
                                                $item->topic_id
                                            ]
                                            ?? 0
                                        ),
                                ];
                            })
                            /*
                             * Do not show empty topics as available
                             * practice choices.
                             */
                            ->filter(
                                fn ($item) =>
                                    $item[
                                        'question_count'
                                    ] > 0
                            )
                            ->values();

                        return [
                            'id' =>
                                $period->id,

                            'title' =>
                                $period->title,

                            'description' =>
                                $period
                                    ->description,

                            'start_date' =>
                                $period
                                    ->start_date
                                    ?->toDateString(),

                            'end_date' =>
                                $period
                                    ->end_date
                                    ?->toDateString(),

                            'learning_state' =>
                                $period
                                    ->end_date
                                    ->toDateString()
                                < $today
                                    ? 'previous'
                                    : 'current',

                            'subject' =>
                                $period
                                    ->gradeSubject
                                    ?->subject
                                    ?->name,

                            'grade' =>
                                $period
                                    ->gradeSubject
                                    ?->gradeLevel
                                    ?->grade_name,

                            'grade_subject_id' =>
                                $period
                                    ->grade_subject_id,

                            'topics' =>
                                $topics,

                            'question_count' =>
                                $topics->sum(
                                    'question_count'
                                ),
                        ];
                    })
                    ->filter(
                        fn ($period) =>
                            $period[
                                'question_count'
                            ] > 0
                    )
                    ->values();

                if ($periodPayload->isEmpty()) {
                    return [
                        'id' =>
                            $model->id,

                        'group_name' =>
                            $model->group_name,

                        'class_code' =>
                            $model->class_code,

                        'academic_year' =>
                            $model->academic_year,

                        'subject' =>
                            $model
                                ->gradeSubject
                                ?->subject
                                ?->name,

                        'grade' =>
                            $model
                                ->gradeSubject
                                ?->gradeLevel
                                ?->grade_name,

                        'grade_subject_id' =>
                            $model
                                ->grade_subject_id,

                        'learning_periods' =>
                            [],

                        'question_count' =>
                            0,
                    ];
                }

                return [
                    'id' =>
                        $model->id,

                    'group_name' =>
                        $model->group_name,

                    'class_code' =>
                        $model->class_code,

                    'academic_year' =>
                        $model->academic_year,

                    'subject' =>
                        $model
                            ->gradeSubject
                            ?->subject
                            ?->name,

                    'grade' =>
                        $model
                            ->gradeSubject
                            ?->gradeLevel
                            ?->grade_name,

                    'grade_subject_id' =>
                        $model
                            ->grade_subject_id,

                    'learning_periods' =>
                        $periodPayload,

                    'question_count' =>
                        $periodPayload->sum(
                            'question_count'
                        ),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => $payload,
        ]);
    }

    /**
     * Return approved questions only after verifying that:
     * - learner belongs to the class;
     * - learning period belongs to that class;
     * - topic is explicitly scheduled in that learning period.
     */
    public function questions(
        Request $request,
        Topic $topic
    ) {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403,
            'Only students can access practice.'
        );

        $validated = $request->validate([
            'group_id' => [
                'required',
                'integer',
                'exists:groups,id',
            ],

            'learning_period_id' => [
                'required',
                'integer',
                'exists:learning_periods,id',
            ],
        ]);

        $group = Group::findOrFail(
            $validated['group_id']
        );

        $period = LearningPeriod::with([
            'topics',
        ])->findOrFail(
            $validated[
                'learning_period_id'
            ]
        );

        /*
         * Reuse the Tutor context access checks rather than
         * trusting IDs supplied by the browser.
         */
        $allowedPeriods =
            $this->context
                ->learningPeriodsForStudent(
                    $student,
                    $group,
                    false
                );

        abort_unless(
            $allowedPeriods->contains(
                'id',
                $period->id
            ),
            403,
            'This learning period is not available to you.'
        );

        abort_unless(
            $period->start_date
                ->toDateString()
            <= now()->toDateString(),
            403,
            'This learning period has not started yet.'
        );

        abort_unless(
            $period
                ->topics
                ->contains(
                    fn ($item) =>
                        (int) $item->topic_id
                        === (int) $topic->id
                ),
            403,
            'This topic is not scheduled in the selected learning period.'
        );

        $questions = Question::query()
            ->where(
                'topic_id',
                $topic->id
            )
            ->whereNull(
                'parent_question_id'
            )
            ->where(
                'status',
                'approved'
            )
            ->where(
                'is_assessment_eligible',
                true
            )
            ->with([
                'learningObjective:id,topic_id,code,objective',
                'subQuestions.learningObjective:id,topic_id,code,objective',
            ])
            ->inRandomOrder()
            ->get();

        return response()->json([
            'data' =>
                $questions
                    ->values(),
            'meta' => [
                'group_id' =>
                    $group->id,

                'learning_period_id' =>
                    $period->id,

                'topic_id' =>
                    $topic->id,

                'question_count' =>
                    $questions->count(),
            ],
        ]);
    }
}
