<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\LearningPeriod;
use App\Models\StudentAssessment;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\LearningPeriodFormativeService;
use App\Services\TutorSessionService;

class HomeController extends Controller
{
    /**
     * Student dashboard analytics.
     *
     * GET /student/statistics
     */
    public function statistics(
        LearningPeriodFormativeService $formativeService,
        TutorSessionService $tutorSessionsService
    )
    {
        $user = Auth::user();

        // Remove stale active sessions for topics that are already mastered
        // and reconcile sessions whose objectives are fully resolved.
        $tutorSessionsService->reconcileCompletedSessionsForStudent($user);

        $studentAssessments = StudentAssessment::query()
            ->with([
                'assessment:id,title,type,due_date',
                'assessment.units:id,name,grade_subject_id',
                'assessment.units.gradeSubject:id,subject_id,grade_level_id',
                'assessment.units.gradeSubject.subject:id,name',
                'assessment.topics:id,topic_name,grade_subject_id,unit_id',
                'assessment.topics.gradeSubject:id,subject_id,grade_level_id',
                'assessment.topics.gradeSubject.subject:id,name',
            ])
            ->where('student_id', $user->id)
            ->orderByDesc('completed_at')
            ->orderByDesc('assigned_at')
            ->get();

        $finishedStatuses = ['completed', 'graded'];
        $resolvedStatuses = ['completed', 'graded', 'under_review'];

        $finishedAssessments = $studentAssessments
            ->whereIn('status', $finishedStatuses)
            ->values();

        $resolvedAssessments = $studentAssessments
            ->whereIn('status', $resolvedStatuses)
            ->values();

        $scorePercentage = static function (StudentAssessment $item): ?float {
            if (!in_array($item->status, ['completed', 'graded'], true)) {
                return null;
            }

            if ((float) $item->max_score > 0) {
                return round(((float) $item->score / (float) $item->max_score) * 100, 1);
            }

            // Legacy rows may contain a percentage directly in score.
            if ($item->score !== null && (float) $item->score >= 0 && (float) $item->score <= 100) {
                return round((float) $item->score, 1);
            }

            return null;
        };

        $scoredPercentages = $finishedAssessments
            ->map($scorePercentage)
            ->filter(fn ($value) => $value !== null)
            ->values();

        $averageScore = $scoredPercentages->isNotEmpty()
            ? round((float) $scoredPercentages->avg(), 1)
            : null;

        $bestScore = $scoredPercentages->isNotEmpty()
            ? round((float) $scoredPercentages->max(), 1)
            : null;

        $totalAssessments = $studentAssessments->count();
        $completedAssessments = $resolvedAssessments->count();
        $pendingAssessments = max(0, $totalAssessments - $completedAssessments);
        $assessmentCompletionRate = $totalAssessments > 0
            ? round(($completedAssessments / $totalAssessments) * 100, 1)
            : 0;

        $totalPractices = $studentAssessments
            ->filter(fn ($item) => $item->assessment?->type === 'practice')
            ->count();

        $subjectPerformance = $finishedAssessments
            ->map(function (StudentAssessment $item) use ($scorePercentage) {
                $percentage = $scorePercentage($item);
                $subject = $this->assessmentSubjectName($item);

                if ($percentage === null || !$subject) {
                    return null;
                }

                return [
                    'subject' => $subject,
                    'score' => $percentage,
                ];
            })
            ->filter()
            ->groupBy('subject')
            ->map(function ($items, $subject) {
                return [
                    'subject' => $subject,
                    'avgScore' => round((float) $items->avg('score'), 1),
                    'attempts' => $items->count(),
                ];
            })
            ->sortByDesc('avgScore')
            ->values();

        $recentScores = $studentAssessments
            ->take(6)
            ->map(function (StudentAssessment $item) use ($scorePercentage) {
                return [
                    'id' => $item->id,
                    'assessment_id' => $item->assessment_id,
                    'title' => $item->assessment?->title ?? 'Assessment',
                    'type' => $item->assessment?->type,
                    'status' => $item->status,
                    'score' => $scorePercentage($item),
                    'date' => optional($item->completed_at ?? $item->assigned_at)?->toIso8601String(),
                    'subject' => $this->assessmentSubjectName($item),
                ];
            })
            ->values();

        /*
         * ---------------------------------------------------------
         * CURRENT LEARNING REALITY
         * ---------------------------------------------------------
         *
         * Dashboard Tutor statistics must describe what the learner
         * is currently expected to learn, not every Tutor session they
         * have ever completed.
         *
         * We therefore scope Tutor progress to the learner's CURRENT
         * published learning periods.
         */
        $groupIds = $user
            ->groups()
            ->pluck('groups.id');

        $today = now()->toDateString();

        $currentLearningPeriods = LearningPeriod::query()
            ->whereIn('group_id', $groupIds)
            ->where('status', 'published')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->with([
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
                'topics' => function ($query) {
                    $query
                        ->orderBy('display_order')
                        ->with([
                            'unit:id,name',
                            'topic:id,topic_name,unit_id,grade_subject_id',
                            'topic.learningObjectives:id,topic_id,code,objective,status,order',
                        ]);
                },
            ])
            ->orderByDesc('start_date')
            ->get();

        $currentPeriodIds =
            $currentLearningPeriods->pluck('id');

        $currentTutorSessions = TutorSession::query()
            ->with([
                'subject:id,name',
                'unit:id,name',
                'topic:id,topic_name',
                'objectives:id,tutor_session_id,learning_objective_id,objective_order,status',
                'objectives.learningObjective:id,objective,code',
            ])
            ->where('student_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('session_mode')->orWhere('session_mode', 'learning');
            })
            ->when(
                $currentPeriodIds->isNotEmpty(),
                fn ($query) =>
                    $query->whereIn(
                        'learning_period_id',
                        $currentPeriodIds
                    ),
                fn ($query) =>
                    $query->whereRaw('1 = 0')
            )
            ->orderByDesc('last_activity_at')
            ->get();

        $currentTutorObjectives =
            TutorSessionObjective::query()
                ->whereHas(
                    'session',
                    fn ($query) =>
                        $query
                            ->where(
                                'student_id',
                                $user->id
                            )
                            ->where(function ($sessionQuery) {
                                $sessionQuery->whereNull('session_mode')
                                    ->orWhere('session_mode', 'learning');
                            })
                            ->when(
                                $currentPeriodIds->isNotEmpty(),
                                fn ($sessionQuery) =>
                                    $sessionQuery->whereIn(
                                        'learning_period_id',
                                        $currentPeriodIds
                                    ),
                                fn ($sessionQuery) =>
                                    $sessionQuery->whereRaw(
                                        '1 = 0'
                                    )
                            )
                )
                ->get([
                    'id',
                    'tutor_session_id',
                    'learning_objective_id',
                    'status',
                    'attempts',
                    'correct_attempts',
                    'completed_at',
                ]);

        /*
         * Count all objectives assigned in current learning periods,
         * including topics that the learner has not started yet.
         */
        $assignedObjectiveCount =
            $currentLearningPeriods
                ->flatMap(
                    fn ($period) =>
                        $period->topics
                )
                ->sum(
                    fn ($periodTopic) =>
                        $periodTopic
                            ->topic
                            ?->learningObjectives
                            ?->where(
                                'status',
                                'active'
                            )
                            ->count()
                        ?? 0
                );

        /*
         * The same learning objective may appear in more than one Tutor
         * session (for example after a retry/review). Count each curriculum
         * objective once and give resolved evidence precedence over a stale
         * active copy. This prevents duplicate sessions from inflating the
         * dashboard totals.
         */
        $objectiveStates = $currentTutorObjectives
            ->filter(fn ($item) => $item->learning_objective_id)
            ->groupBy('learning_objective_id')
            ->map(function ($rows) {
                if ($rows->contains('status', 'completed')) {
                    return 'completed';
                }

                if ($rows->contains('status', 'needs_review')) {
                    return 'needs_review';
                }

                if ($rows->contains('status', 'in_progress')) {
                    return 'in_progress';
                }

                return 'not_started';
            });

        $masteredObjectives = $objectiveStates
            ->filter(fn ($status) => $status === 'completed')
            ->count();

        $reviewObjectives = $objectiveStates
            ->filter(fn ($status) => $status === 'needs_review')
            ->count();

        $activeObjectives = $objectiveStates
            ->filter(fn ($status) => $status === 'in_progress')
            ->count();

        $coveredObjectives =
            $masteredObjectives
            + $reviewObjectives;

        $totalTutorObjectives =
            max(
                $assignedObjectiveCount,
                $objectiveStates->count()
            );

        $masteryRate =
            $totalTutorObjectives > 0
                ? round(
                    (
                        $masteredObjectives
                        / $totalTutorObjectives
                    ) * 100,
                    1
                )
                : 0;

        $coverageRate =
            $totalTutorObjectives > 0
                ? round(
                    (
                        $coveredObjectives
                        / $totalTutorObjectives
                    ) * 100,
                    1
                )
                : 0;

        /*
         * Topic-level progress for the current periods.
         */
        $currentTopicAssignments =
            $currentLearningPeriods
                ->flatMap(function ($period) {
                    return $period->topics->map(
                        function ($periodTopic) use ($period) {
                            $periodTopic->dashboard_period =
                                $period;

                            return $periodTopic;
                        }
                    );
                })
                ->values();

        $coveredTopicKeys =
            $currentTutorSessions
                ->where(
                    'status',
                    'completed'
                )
                ->map(
                    fn ($session) =>
                        $session->learning_period_id
                        . ':'
                        . $session->topic_id
                )
                ->unique()
                ->values();

        $totalCurrentTopics =
            $currentTopicAssignments->count();

        $coveredCurrentTopics =
            $currentTopicAssignments
                ->filter(
                    fn ($assignment) =>
                        $coveredTopicKeys->contains(
                            $assignment
                                ->learning_period_id
                            . ':'
                            . $assignment
                                ->topic_id
                        )
                )
                ->count();

        /*
         * Prefer an ACTIVE Tutor session in a current learning
         * period. This is the learner's real ongoing topic.
         */
        $activeTutorSession =
            $currentTutorSessions
                ->firstWhere(
                    'status',
                    'active'
                );

        $focusPeriod = null;
        $focusTopicAssignment = null;
        $currentLearningStatus = null;

        if ($activeTutorSession) {
            $focusPeriod =
                $currentLearningPeriods
                    ->firstWhere(
                        'id',
                        $activeTutorSession
                            ->learning_period_id
                    );

            $focusTopicAssignment =
                $focusPeriod
                    ?->topics
                    ?->firstWhere(
                        'topic_id',
                        $activeTutorSession
                            ->topic_id
                    );

            $currentLearningStatus =
                'ongoing';
        } elseif ($currentLearningPeriods->isNotEmpty()) {
            /*
             * No active session:
             * choose the first CURRENTLY ASSIGNED topic that has not
             * already been completed. Never blindly point to topic #1.
             */
            foreach (
                $currentLearningPeriods
                as $period
            ) {
                $nextAssignment =
                    $period
                        ->topics
                        ->first(
                            function (
                                $periodTopic
                            ) use (
                                $coveredTopicKeys,
                                $period
                            ) {
                                return !$coveredTopicKeys
                                    ->contains(
                                        $period->id
                                        . ':'
                                        . $periodTopic
                                            ->topic_id
                                    );
                            }
                        );

                if ($nextAssignment) {
                    $focusPeriod = $period;
                    $focusTopicAssignment =
                        $nextAssignment;
                    $currentLearningStatus =
                        'next_topic';
                    break;
                }
            }

            if (
                !$focusTopicAssignment
                && $totalCurrentTopics > 0
            ) {
                $focusPeriod =
                    $currentLearningPeriods->first();

                $currentLearningStatus =
                    'all_topics_covered';
            }
        }

        $currentLearning = null;

        if ($focusPeriod) {
            if (
                $currentLearningStatus ===
                'all_topics_covered'
            ) {
                $formative = $formativeService
                    ->statusForStudent(
                        $user,
                        $focusPeriod
                    );

                $currentLearning = [
                    'status' =>
                        'all_topics_covered',
                    'all_topics_covered' =>
                        true,
                    'group_id' =>
                        $focusPeriod->group_id,
                    'learning_period_id' =>
                        $focusPeriod->id,
                    'learning_period' =>
                        $focusPeriod->title,
                    'subject' =>
                        $focusPeriod
                            ->gradeSubject
                            ?->subject
                            ?->name,
                    'total_topics' =>
                        $totalCurrentTopics,
                    'covered_topics' =>
                        $coveredCurrentTopics,
                    'progress_percent' =>
                        $totalCurrentTopics > 0
                            ? round(
                                (
                                    $coveredCurrentTopics
                                    / $totalCurrentTopics
                                ) * 100,
                                1
                            )
                            : 100,
                    'message' =>
                        ($formative['status'] ?? null) === 'completed'
                            ? 'You have covered all topics and completed the required formative assessment for this learning period.'
                            : 'You have covered all topics. Complete the required formative assessment to finish this learning period.',
                    'formative_assessment' =>
                        $formative,
                    'period_complete' =>
                        in_array(
                            $formative['status'] ?? null,
                            ['completed', 'graded'],
                            true
                        ),
                ];
            } elseif ($focusTopicAssignment) {
                $focusSession =
                    $activeTutorSession
                    && (int) $activeTutorSession
                        ->learning_period_id
                        === (int) $focusPeriod->id
                    && (int) $activeTutorSession
                        ->topic_id
                        === (int) $focusTopicAssignment
                            ->topic_id
                        ? $activeTutorSession
                        : null;

                $sessionObjectives =
                    $focusSession
                        ?->objectives
                    ?? collect();

                $currentObjective =
                    $sessionObjectives
                        ->firstWhere(
                            'status',
                            'in_progress'
                        );

                $resolvedInTopic =
                    $sessionObjectives
                        ->whereIn(
                            'status',
                            [
                                'completed',
                                'needs_review',
                            ]
                        )
                        ->count();

                $topicObjectiveCount =
                    $focusTopicAssignment
                        ->topic
                        ?->learningObjectives
                        ?->where(
                            'status',
                            'active'
                        )
                        ->count()
                    ?? $sessionObjectives->count();

                $currentLearning = [
                    'status' =>
                        $currentLearningStatus,
                    'all_topics_covered' =>
                        false,
                    'session_id' =>
                        $focusSession?->id,
                    'group_id' =>
                        $focusPeriod->group_id,
                    'learning_period_id' =>
                        $focusPeriod->id,
                    'learning_period' =>
                        $focusPeriod->title,
                    'grade_subject_id' =>
                        $focusPeriod
                            ->grade_subject_id,
                    'unit_id' =>
                        $focusTopicAssignment
                            ->unit_id,
                    'topic_id' =>
                        $focusTopicAssignment
                            ->topic_id,
                    'title' =>
                        $focusTopicAssignment
                            ->topic
                            ?->topic_name,
                    'subject' =>
                        $focusPeriod
                            ->gradeSubject
                            ?->subject
                            ?->name,
                    'unit' =>
                        $focusTopicAssignment
                            ->unit
                            ?->name,
                    'topic' =>
                        $focusTopicAssignment
                            ->topic
                            ?->topic_name,
                    'current_objective' =>
                        $currentObjective
                            ?->learningObjective
                            ?->objective,
                    'current_objective_code' =>
                        $currentObjective
                            ?->learningObjective
                            ?->code,
                    'objective_position' =>
                        $currentObjective
                            ?->objective_order,
                    'total_objectives' =>
                        $topicObjectiveCount,
                    'covered_objectives' =>
                        $resolvedInTopic,
                    'mastered_objectives' =>
                        $sessionObjectives
                            ->where(
                                'status',
                                'completed'
                            )
                            ->count(),
                    'review_objectives' =>
                        $sessionObjectives
                            ->where(
                                'status',
                                'needs_review'
                            )
                            ->count(),
                    'topic_progress_percent' =>
                        $topicObjectiveCount > 0
                            ? round(
                                (
                                    $resolvedInTopic
                                    / $topicObjectiveCount
                                ) * 100,
                                1
                            )
                            : 0,
                    'total_topics' =>
                        $totalCurrentTopics,
                    'covered_topics' =>
                        $coveredCurrentTopics,
                    'progress_percent' =>
                        $totalCurrentTopics > 0
                            ? round(
                                (
                                    $coveredCurrentTopics
                                    / $totalCurrentTopics
                                ) * 100,
                                1
                            )
                            : 0,
                    'last_activity_at' =>
                        optional(
                            $focusSession
                                ?->last_activity_at
                        )
                        ?->toIso8601String(),
                ];
            }
        }

        $tutorSessions = TutorSession::query()
            ->where('student_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('session_mode')->orWhere('session_mode', 'learning');
            })
            ->get(['id', 'status', 'started_at', 'last_activity_at', 'ended_at']);

        $lastSixWeeks = collect(range(5, 1))->map(function ($weeksAgo) use ($user) {
            $start = now()->startOfWeek()->subWeeks($weeksAgo);
            $end = (clone $start)->endOfWeek();

            $assessments = StudentAssessment::query()
                ->where('student_id', $user->id)
                ->whereIn('status', ['completed', 'graded'])
                ->whereBetween('completed_at', [$start, $end])
                ->count();

            $objectives = TutorSessionObjective::query()
                ->whereHas('session', fn ($query) => $query
                    ->where('student_id', $user->id)
                    ->where(function ($sessionQuery) {
                        $sessionQuery->whereNull('session_mode')->orWhere('session_mode', 'learning');
                    }))
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$start, $end])
                ->count();

            return [
                'label' => $start->format('M j'),
                'assessments' => $assessments,
                'objectives' => $objectives,
            ];
        })->push((function () use ($user) {
            $start = now()->startOfWeek();
            $end = now()->endOfWeek();

            return [
                'label' => $start->format('M j'),
                'assessments' => StudentAssessment::query()
                    ->where('student_id', $user->id)
                    ->whereIn('status', ['completed', 'graded'])
                    ->whereBetween('completed_at', [$start, $end])
                    ->count(),
                'objectives' => TutorSessionObjective::query()
                    ->whereHas('session', fn ($query) => $query
                    ->where('student_id', $user->id)
                    ->where(function ($sessionQuery) {
                        $sessionQuery->whereNull('session_mode')->orWhere('session_mode', 'learning');
                    }))
                    ->where('status', 'completed')
                    ->whereBetween('completed_at', [$start, $end])
                    ->count(),
            ];
        })());

        return response()->json([
            'student' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'summary' => [
                'total_assessments' => $totalAssessments,
                'completed_assessments' => $completedAssessments,
                'pending_assessments' => $pendingAssessments,
                'assessment_completion_rate' => $assessmentCompletionRate,
                'average_score' => $averageScore,
                'best_score' => $bestScore,
                'total_practices' => $totalPractices,
                'tutor_sessions' => $tutorSessions->count(),
                'active_tutor_sessions' => $tutorSessions->where('status', 'active')->count(),
            ],
            'tutor_progress' => [
                'total_objectives' => $totalTutorObjectives,
                'mastered_objectives' => $masteredObjectives,
                'needs_review' => $reviewObjectives,
                'active_objectives' => $activeObjectives,
                'covered_objectives' => $coveredObjectives,
                'mastery_rate' => $masteryRate,
                'coverage_rate' => $coverageRate,
                'total_topics' => $totalCurrentTopics,
                'covered_topics' => $coveredCurrentTopics,
                'current_periods' => $currentLearningPeriods->count(),
            ],
            'current_learning' => $currentLearning,
            'subjectPerformance' => $subjectPerformance,
            'recentScores' => $recentScores,
            'weeklyProgress' => $lastSixWeeks->values(),

            // Compatibility fields for older dashboard clients.
            'totalAssessments' => $totalAssessments,
            'totalPractices' => $totalPractices,
            'completedAssessments' => $completedAssessments,
            'pendingAssessments' => $pendingAssessments,
        ]);
    }


    /**
     * Resolve an assessment subject through the curriculum relationships that
     * actually exist in this schema. Assessments do not own grade_subject_id.
     */
    private function assessmentSubjectName(StudentAssessment $studentAssessment): ?string
    {
        $assessment = $studentAssessment->assessment;

        if (!$assessment) {
            return null;
        }

        $unitSubject = $assessment->units
            ?->first()?->gradeSubject?->subject?->name;

        if ($unitSubject) {
            return $unitSubject;
        }

        return $assessment->topics
            ?->first()?->gradeSubject?->subject?->name;
    }

    public function subjectsOverview()
    {
        $subjects = Subject::withCount(['topics', 'questions'])
            ->with(['topics.gradeLevels:id,grade_name'])
            ->select('id', 'name')
            ->get()
            ->flatMap(function ($subject) {
                $gradeLevels = $subject->topics
                    ->flatMap(fn ($topic) => $topic->gradeLevels)
                    ->unique('id');

                if ($gradeLevels->isEmpty()) {
                    return [[
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'grade_level' => null,
                        'grade_name' => 'All Grades',
                        'topics_count' => $subject->topics_count,
                        'questions_count' => $subject->questions_count,
                    ]];
                }

                return $gradeLevels->map(function ($grade) use ($subject) {
                    $topicsCount = $subject->topics->filter(function ($topic) use ($grade) {
                        return $topic->gradeLevels->contains('id', $grade->id);
                    })->count();

                    $questionsCount = $subject->questions()
                        ->whereHas('topic.gradeLevels', function ($q) use ($grade) {
                            $q->where('grade_levels.id', $grade->id);
                        })
                        ->count();

                    return [
                        'id' => $subject->id . '-' . $grade->id,
                        'subject_id' => $subject->id,
                        'grade_id' => $grade->id,
                        'name' => $subject->name,
                        'grade_level' => $grade->grade_name,
                        'grade_name' => $grade->grade_name,
                        'topics_count' => $topicsCount,
                        'questions_count' => $questionsCount,
                    ];
                });
            });

        return response()->json($subjects);
    }

    public function subjectTopics(Subject $subject, Request $request)
    {
        $request->validate([
            'grade_id' => 'sometimes|exists:grade_levels,id',
        ]);

        $query = $subject->topics()
            ->withCount('questions')
            ->select('id', 'topic_name', 'subject_id');

        if ($request->has('grade_id')) {
            $query->whereHas('gradeLevels', function ($q) use ($request) {
                $q->where('grade_levels.id', $request->grade_id);
            });
        }

        $topics = $query->get();

        return response()->json([
            'subject' => $subject->name,
            'topics' => $topics,
        ]);
    }

    public function topicQuestions(Topic $topic)
    {
        $questions = $topic->questions()
            ->select('id', 'question', 'question_type', 'difficulty_level', 'options', 'is_math')
            ->get();

        return response()->json([
            'topic' => $topic->topic_name,
            'questions' => $questions,
        ]);
    }

    /**
     * Summarized report: number of questions per subject
     * GET /reports/questions-per-subject
     */
    public function questionsPerSubject()
    {
        $summary = Subject::query()
            ->leftJoin('grade_subjects', 'grade_subjects.subject_id', '=', 'subjects.id')
            ->leftJoin('grade_levels', 'grade_levels.id', '=', 'grade_subjects.grade_level_id')
            ->leftJoin('topics', 'topics.grade_subject_id', '=', 'grade_subjects.id')
            ->leftJoin('questions', 'questions.topic_id', '=', 'topics.id')
            ->select([
                'subjects.id as subject_id',
                'subjects.name as subject_name',
                'grade_levels.id as grade_id',
                'grade_levels.grade_name as grade_name',
                DB::raw('COUNT(questions.id) as questions_count'),
            ])
            ->groupBy('subjects.id', 'subjects.name', 'grade_levels.id', 'grade_levels.grade_name')
            ->orderBy('subjects.name')
            ->orderBy('grade_levels.grade_name')
            ->get();

        return response()->json($summary);
    }
}
