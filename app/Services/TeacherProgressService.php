<?php

namespace App\Services;

use App\Models\GradeSubject;
use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\LearningPeriodTopic;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherProgressService
{
   
    public function dashboard(
        User $teacher,
        array $filters = []
    ): array {
        $this->ensureTeacherOrAdmin($teacher);

        $gradeSubjects = $this->accessibleGradeSubjects($teacher);

        $periods = $this->periodsQuery(
            $teacher,
            $filters
        )
            ->with($this->periodRelations())
            ->get();

        $groups = $this->groupsForPeriods(
            $periods,
            $filters
        );

        $students = $this->studentsForGroups($groups);

        $sessions = $this->sessionsForPeriods(
            $periods,
            $filters
        );

        $progress = $this->buildProgressIndex(
            $students,
            $periods,
            $sessions
        );

        $topicRows = $this->topicRows(
            $periods,
            $students,
            $progress,
            $filters
        );

        $studentRows = $this->studentRows(
            $students,
            $periods,
            $progress,
            $filters
        );

        $periodRows = $this->periodRows(
            $periods,
            $students,
            $progress,
            $filters
        );

        $sessionCount = $sessions->count();

        $activeLearnerIds = $sessions
            ->pluck('student_id')
            ->unique()
            ->values();

        $messageCount = $sessions->isEmpty()
            ? 0
            : DB::table('tutor_messages')
                ->whereIn(
                    'tutor_session_id',
                    $sessions->pluck('id')
                )
                ->count();

        return [
            'filters' => [
                'grade_subject_id' =>
                    $filters['grade_subject_id'] ?? null,

                'learning_period_id' =>
                    $filters['learning_period_id'] ?? null,

                'group_id' =>
                    $filters['group_id'] ?? null,
            ],

            /*
             * Used directly by dashboard dropdowns.
             */
            'available_filters' => [
                'subjects' => $this->subjectRows(
                    $gradeSubjects
                ),

                'groups' => $this->groupRows(
                    $groups
                ),

                'learning_periods' => $periods
                    ->map(fn ($period) => [
                        'id' => $period->id,
                        'title' => $period->title,
                        'grade_subject_id' =>
                            $period->grade_subject_id,
                        'group_id' => $period->group_id,
                        'group_name' =>
                            $period->group?->group_name,
                        'subject' =>
                            $period->gradeSubject
                                ?->subject
                                ?->name,
                        'grade' =>
                            $period->gradeSubject
                                ?->gradeLevel
                                ?->grade_name,
                        'start_date' =>
                            $period->start_date
                                ?->toDateString(),
                        'end_date' =>
                            $period->end_date
                                ?->toDateString(),
                    ])
                    ->values(),
            ],

            'summary' => [
                'students' => $students->count(),

                'active_learners' =>
                    $activeLearnerIds->count(),

                'students_started' =>
                    $studentRows
                        ->where('started_topics', '>', 0)
                        ->count(),

                'students_completed' =>
                    $studentRows
                        ->filter(
                            fn ($row) =>
                                $row['topics_available'] > 0 &&
                                $row['completed_topics'] >=
                                $row['topics_available']
                        )
                        ->count(),

                'overall_progress' =>
                    $this->average(
                        $studentRows->pluck('progress')
                    ),

                'learning_periods' =>
                    $periods->count(),

                'topics_available' =>
                    $periods
                        ->flatMap(
                            fn ($period) =>
                                $period->topics
                        )
                        ->count(),

                'topics_started' =>
                    $topicRows
                        ->where(
                            'started_learners',
                            '>',
                            0
                        )
                        ->count(),

                'topics_completed' =>
                    $topicRows
                        ->filter(
                            fn ($row) =>
                                $row['students'] > 0 &&
                                $row['completed_learners'] >=
                                $row['students']
                        )
                        ->count(),

                'tutor_sessions' =>
                    $sessionCount,

                'active_sessions' =>
                    $sessions
                        ->where('status', 'active')
                        ->count(),

                'completed_sessions' =>
                    $sessions
                        ->where('status', 'completed')
                        ->count(),

                'abandoned_sessions' =>
                    $sessions
                        ->where('status', 'abandoned')
                        ->count(),

                'tutor_messages' =>
                    $messageCount,
            ],

            'students_needing_attention' =>
                $studentRows
                    ->filter(
                        fn ($row) =>
                            !empty($row['needs_teacher_attention'])
                            || (
                                $row['started_topics'] > 0 &&
                                $row['progress'] < 40
                            )
                    )
                    ->sortByDesc('needs_teacher_attention')
                    ->sortByDesc('max_struggle_level')
                    ->take(10)
                    ->values()
                    ->all(),

            'learning_periods' =>
                $periodRows->values()->all(),

            'topics' =>
                $topicRows->values()->all(),

            'students' =>
                $studentRows->values()->all(),
        ];
    }

    /**
     * ============================================================
     * SUBJECTS / TEACHING AREAS
     * ============================================================
     */
    public function subjects(
        User $teacher
    ): Collection {
        $this->ensureTeacherOrAdmin($teacher);

        return $this->subjectRows(
            $this->accessibleGradeSubjects($teacher)
        );
    }

    /**
     * ============================================================
     * LEARNING PERIOD FILTER OPTIONS
     * ============================================================
     */
    public function learningPeriods(
        User $teacher,
        array $filters = []
    ): Collection {
        $this->ensureTeacherOrAdmin($teacher);

        return $this->periodsQuery(
            $teacher,
            $filters
        )
            ->with([
                'group:id,group_name,class_code',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ])
            ->get()
            ->map(function ($period) {
                return [
                    'id' => $period->id,
                    'title' => $period->title,

                    'grade_subject_id' =>
                        $period->grade_subject_id,

                    'group_id' =>
                        $period->group_id,

                    'group_name' =>
                        $period->group?->group_name,

                    'class_code' =>
                        $period->group?->class_code,

                    'subject' =>
                        $period->gradeSubject
                            ?->subject
                            ?->name,

                    'grade' =>
                        $period->gradeSubject
                            ?->gradeLevel
                            ?->grade_name,

                    'start_date' =>
                        $period->start_date
                            ?->toDateString(),

                    'end_date' =>
                        $period->end_date
                            ?->toDateString(),

                    'status' =>
                        $period->status,
                ];
            })
            ->values();
    }

    /**
     * ============================================================
     * STUDENTS
     * ============================================================
     */
    public function students(
        User $teacher,
        array $filters = []
    ): Collection {
        $this->ensureTeacherOrAdmin($teacher);

        $periods = $this->periodsQuery(
            $teacher,
            $filters
        )
            ->with($this->periodRelations())
            ->get();

        $groups = $this->groupsForPeriods(
            $periods,
            $filters
        );

        $students = $this->studentsForGroups(
            $groups
        );

        $sessions = $this->sessionsForPeriods(
            $periods,
            $filters
        );

        $progress = $this->buildProgressIndex(
            $students,
            $periods,
            $sessions
        );

        return $this->studentRows(
            $students,
            $periods,
            $progress,
            $filters
        );
    }

    /**
     * ============================================================
     * ONE LEARNING PERIOD
     * ============================================================
     */
    public function learningPeriod(
        User $teacher,
        LearningPeriod $learningPeriod,
        array $filters = []
    ): array {
        $this->ensureCanViewLearningPeriod(
            $teacher,
            $learningPeriod
        );

        $learningPeriod->load(
            $this->periodRelations()
        );

        $periods = collect([
            $learningPeriod,
        ]);

        $effectiveFilters = array_merge(
            $filters,
            [
                'learning_period_id' =>
                    $learningPeriod->id,
            ]
        );

        $groups = $this->groupsForPeriods(
            $periods,
            $effectiveFilters
        );

        $students = $this->studentsForGroups(
            $groups
        );

        $sessions = $this->sessionsForPeriods(
            $periods,
            $effectiveFilters
        );

        $progress = $this->buildProgressIndex(
            $students,
            $periods,
            $sessions
        );

        $topics = $this->topicRows(
            $periods,
            $students,
            $progress,
            $effectiveFilters
        );

        $studentRows = $this->studentRows(
            $students,
            $periods,
            $progress,
            $effectiveFilters
        );

        return [
            'learning_period' => [
                'id' =>
                    $learningPeriod->id,

                'title' =>
                    $learningPeriod->title,

                'description' =>
                    $learningPeriod->description,

                'grade_subject_id' =>
                    $learningPeriod->grade_subject_id,

                'group_id' =>
                    $learningPeriod->group_id,

                'group_name' =>
                    $learningPeriod->group
                        ?->group_name,

                'class_code' =>
                    $learningPeriod->group
                        ?->class_code,

                'start_date' =>
                    $learningPeriod
                        ->start_date
                        ?->toDateString(),

                'end_date' =>
                    $learningPeriod
                        ->end_date
                        ?->toDateString(),

                'status' =>
                    $learningPeriod->status,

                'published_at' =>
                    $learningPeriod
                        ->published_at
                        ?->toISOString(),

                'subject' =>
                    $learningPeriod
                        ->gradeSubject
                        ?->subject
                        ?->name,

                'grade' =>
                    $learningPeriod
                        ->gradeSubject
                        ?->gradeLevel
                        ?->grade_name,
            ],

            'summary' => [
                'students' =>
                    $students->count(),

                'active_learners' =>
                    $sessions
                        ->pluck('student_id')
                        ->unique()
                        ->count(),

                'topics' =>
                    $learningPeriod
                        ->topics
                        ->count(),

                'topics_started' =>
                    $topics
                        ->where(
                            'started_learners',
                            '>',
                            0
                        )
                        ->count(),

                'topics_completed' =>
                    $topics
                        ->filter(
                            fn ($row) =>
                                $row['students'] > 0 &&
                                $row['completed_learners'] >=
                                $row['students']
                        )
                        ->count(),

                'overall_progress' =>
                    $this->average(
                        $studentRows
                            ->pluck('progress')
                    ),

                'sessions' =>
                    $sessions->count(),

                'completed_sessions' =>
                    $sessions
                        ->where(
                            'status',
                            'completed'
                        )
                        ->count(),
            ],

            'topics' =>
                $topics->values()->all(),

            'students' =>
                $studentRows->values()->all(),
        ];
    }

    /**
     * ============================================================
     * ONE STUDENT
     * ============================================================
     */
    public function student(
        User $teacher,
        User $student,
        array $filters = []
    ): array {
        $this->ensureTeacherOrAdmin($teacher);

        $periods = $this->periodsQuery(
            $teacher,
            $filters
        )
            ->with($this->periodRelations())
            ->get();

        $groups = $this->groupsForPeriods(
            $periods,
            $filters
        );

        $studentBelongsToScope = $groups
            ->contains(function ($group) use ($student) {
                return $group
                    ->students()
                    ->where(
                        'users.id',
                        $student->id
                    )
                    ->exists();
            });

        abort_unless(
            $studentBelongsToScope,
            404,
            'The student is not available in the selected teaching scope.'
        );

        $students = collect([
            $student,
        ]);

        $studentFilters = array_merge(
            $filters,
            [
                'student_id' =>
                    $student->id,
            ]
        );

        $sessions = $this->sessionsForPeriods(
            $periods,
            $studentFilters
        );

        $progress = $this->buildProgressIndex(
            $students,
            $periods,
            $sessions
        );

        $studentRow = $this->studentRows(
            $students,
            $periods,
            $progress,
            $filters
        )->first();

        $activity = $sessions
            ->sortByDesc('last_activity_at')
            ->take(20)
            ->map(function ($session) {
                return [
                    'id' =>
                        $session->id,

                    'group_id' =>
                        $session->group_id,

                    'group_name' =>
                        $session->group
                            ?->group_name,

                    'learning_period_id' =>
                        $session->learning_period_id,

                    'topic_id' =>
                        $session->topic_id,

                    'topic_name' =>
                        $session->topic
                            ?->topic_name,

                    'status' =>
                        $session->status,

                    'started_at' =>
                        $session
                            ->started_at
                            ?->toISOString(),

                    'last_activity_at' =>
                        $session
                            ->last_activity_at
                            ?->toISOString(),

                    'ended_at' =>
                        $session
                            ->ended_at
                            ?->toISOString(),

                    'messages_count' =>
                        $session->messages_count ?? 0,
                ];
            })
            ->values();

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],

            'summary' => $studentRow,

            'learning_periods' =>
                $this->periodRows(
                    $periods,
                    $students,
                    $progress,
                    $filters
                )
                    ->values()
                    ->all(),

            'topics' =>
                $this->topicRows(
                    $periods,
                    $students,
                    $progress,
                    $filters
                )
                    ->values()
                    ->all(),

            'activity' =>
                $activity->all(),
        ];
    }

    /**
     * ============================================================
     * RECENT ACTIVITY
     * ============================================================
     */
    public function activity(
        User $teacher,
        array $filters = [],
        int $limit = 30
    ): Collection {
        $this->ensureTeacherOrAdmin($teacher);

        $periods = $this->periodsQuery(
            $teacher,
            $filters
        )->get();

        if ($periods->isEmpty()) {
            return collect();
        }

        $query = TutorSession::query()
            ->whereIn(
                'learning_period_id',
                $periods->pluck('id')
            )
            ->with([
                'student:id,name,email',
                'group:id,group_name,class_code',
                'learningPeriod:id,title',
                'topic:id,topic_name',
            ])
            ->withCount('messages');

        if (!empty($filters['group_id'])) {
            $query->where(
                'group_id',
                $filters['group_id']
            );
        }

        return $query
            ->latest('last_activity_at')
            ->limit(min($limit, 100))
            ->get()
            ->map(function ($session) {
                return [
                    'session_id' =>
                        $session->id,

                    'student' => [
                        'id' =>
                            $session->student?->id,

                        'name' =>
                            $session->student?->name,
                    ],

                    'group' => [
                        'id' =>
                            $session->group?->id,

                        'name' =>
                            $session->group
                                ?->group_name,

                        'class_code' =>
                            $session->group
                                ?->class_code,
                    ],

                    'learning_period' => [
                        'id' =>
                            $session
                                ->learningPeriod
                                ?->id,

                        'title' =>
                            $session
                                ->learningPeriod
                                ?->title,
                    ],

                    'topic' => [
                        'id' =>
                            $session->topic?->id,

                        'name' =>
                            $session->topic
                                ?->topic_name,
                    ],

                    'status' =>
                        $session->status,

                    'started_at' =>
                        $session
                            ->started_at
                            ?->toISOString(),

                    'last_activity_at' =>
                        $session
                            ->last_activity_at
                            ?->toISOString(),

                    'messages_count' =>
                        $session->messages_count,
                ];
            });
    }

    /**
     * ============================================================
     * ACCESSIBLE TEACHING AREAS
     * ============================================================
     */
    protected function accessibleGradeSubjects(
        User $teacher
    ): Collection {
        $query = GradeSubject::query()
            ->with([
                'subject:id,name',
                'gradeLevel:id,grade_name',
            ]);

        if ($teacher->role !== 'admin') {
            $query
                ->where(
                    'teacher_id',
                    $teacher->id
                )
                ->where(
                    'school_id',
                    $teacher->school_id
                );
        } elseif ($teacher->school_id) {
            $query->where(
                'school_id',
                $teacher->school_id
            );
        }

        return $query
            ->orderBy('grade_level_id')
            ->orderBy('subject_id')
            ->get();
    }

    /**
     * ============================================================
     * BASE LEARNING PERIOD QUERY
     * ============================================================
     */
    protected function periodsQuery(
        User $teacher,
        array $filters = []
    ): Builder {
        $accessibleIds =
            $this->accessibleGradeSubjects(
                $teacher
            )
                ->pluck('id');

        $query = LearningPeriod::query()
            ->where('status', 'published')
            ->whereIn(
                'grade_subject_id',
                $accessibleIds
            );

        if (!empty($filters['grade_subject_id'])) {
            $gradeSubjectId =
                (int) $filters['grade_subject_id'];

            abort_unless(
                $accessibleIds->contains(
                    $gradeSubjectId
                ),
                403,
                'You are not authorized to view this subject.'
            );

            $query->where(
                'grade_subject_id',
                $gradeSubjectId
            );
        }

        if (!empty($filters['learning_period_id'])) {
            $query->whereKey(
                $filters['learning_period_id']
            );
        }

        if (!empty($filters['group_id'])) {
            $query->where(
                'group_id',
                $filters['group_id']
            );
        }

        return $query
            ->orderByDesc('start_date');
    }

    /**
     * ============================================================
     * GROUPS DERIVED FROM LEARNING PERIODS
     * ============================================================
     */
    protected function groupsForPeriods(
        Collection $periods,
        array $filters = []
    ): Collection {
        $groupIds = $periods
            ->pluck('group_id')
            ->filter()
            ->unique()
            ->values();

        if ($groupIds->isEmpty()) {
            return collect();
        }

        $query = Group::query()
            ->whereIn(
                'id',
                $groupIds
            );

        if (!empty($filters['group_id'])) {
            $query->whereKey(
                $filters['group_id']
            );
        }

        return $query
            ->orderBy('group_name')
            ->get();
    }

    /**
     * ============================================================
     * STUDENTS FROM GROUPS
     * ============================================================
     */
    protected function studentsForGroups(
        Collection $groups
    ): Collection {
        if ($groups->isEmpty()) {
            return collect();
        }

        $studentIds = collect();

        foreach ($groups as $group) {
            $ids = $group
                ->students()
                ->where(
                    'users.role',
                    'student'
                )
                ->pluck('users.id');

            $studentIds =
                $studentIds->merge($ids);
        }

        $studentIds = $studentIds
            ->unique()
            ->values();

        if ($studentIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn(
                'id',
                $studentIds
            )
            ->select([
                'id',
                'name',
                'email',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * ============================================================
     * SESSIONS
     * ============================================================
     */
    protected function sessionsForPeriods(
        Collection $periods,
        array $filters = []
    ): Collection {
        if ($periods->isEmpty()) {
            return collect();
        }

        $query = TutorSession::query()
            ->whereIn(
                'learning_period_id',
                $periods->pluck('id')
            )
            ->with([
                'group:id,group_name,class_code',

                'objectives:id,tutor_session_id,learning_objective_id,status,attempts,correct_attempts',
                'objectives.learningObjective:id,objective',
                'objectives.responseEvaluations:id,tutor_session_objective_id,classification,understanding_score,misconception,created_at',

                'topic:id,topic_name',
            ])
            ->withCount('messages');

        if (!empty($filters['group_id'])) {
            $query->where(
                'group_id',
                $filters['group_id']
            );
        }

        if (!empty($filters['student_id'])) {
            $query->where(
                'student_id',
                $filters['student_id']
            );
        }

        return $query->get();
    }

    /**
     * ============================================================
     * PROGRESS INDEX
     * ============================================================
     */
    protected function buildProgressIndex(
        Collection $students,
        Collection $periods,
        Collection $sessions
    ): array {
        $index = [];

        $periodTopics = $periods
            ->flatMap(
                fn ($period) =>
                    $period->topics
            )
            ->values();

        foreach ($students as $student) {
            foreach ($periodTopics as $periodTopic) {

                $topicId =
                    $periodTopic->topic_id;

                $topicObjectives =
                    $periodTopic->topic
                        ? $periodTopic
                            ->topic
                            ->learningObjectives()
                            ->where(
                                'status',
                                'active'
                            )
                            ->orderBy('order')
                            ->get(['id'])
                        : collect();

                $objectiveIds =
                    $topicObjectives
                        ->pluck('id')
                        ->map(
                            fn ($id) =>
                                (int) $id
                        )
                        ->values();

                $studentSessions =
                    $sessions->filter(
                        fn ($session) =>
                            (int) $session->student_id ===
                                (int) $student->id
                            &&
                            (int) $session->topic_id ===
                                (int) $topicId
                            &&
                            (int) $session->learning_period_id ===
                                (int) $periodTopic
                                    ->learning_period_id
                    );

                $objectiveStates = [];

                foreach ($studentSessions as $session) {
                    foreach (
                        $session->objectives
                        as $sessionObjective
                    ) {
                        $objectiveId =
                            (int) $sessionObjective
                                ->learning_objective_id;

                        if (
                            !isset(
                                $objectiveStates[
                                    $objectiveId
                                ]
                            )
                        ) {
                            $objectiveStates[
                                $objectiveId
                            ] = [
                                'completed' => false,
                                'attempts' => 0,
                                'correct_attempts' => 0,
                                'struggle_level' => 0,
                                'needs_teacher_attention' => false,
                                'attention_reason' => null,
                                'misconception' => null,
                                'objective_name' => $sessionObjective->learningObjective?->objective,
                            ];
                        }

                        $objectiveStates[
                            $objectiveId
                        ]['completed'] =
                            $objectiveStates[
                                $objectiveId
                            ]['completed']
                            ||
                            $sessionObjective
                                ->status ===
                                'completed';

                        $objectiveStates[
                            $objectiveId
                        ]['attempts'] +=
                            (int) $sessionObjective
                                ->attempts;

                        $objectiveStates[
                            $objectiveId
                        ]['correct_attempts'] +=
                            (int) $sessionObjective
                                ->correct_attempts;

                        $struggle = $this->objectiveStruggleSummary($sessionObjective);

                        if ($struggle['struggle_level'] > $objectiveStates[$objectiveId]['struggle_level']) {
                            $objectiveStates[$objectiveId]['struggle_level'] = $struggle['struggle_level'];
                            $objectiveStates[$objectiveId]['attention_reason'] = $struggle['attention_reason'];
                            $objectiveStates[$objectiveId]['misconception'] = $struggle['misconception'];
                        }

                        $objectiveStates[$objectiveId]['needs_teacher_attention'] =
                            $objectiveStates[$objectiveId]['needs_teacher_attention']
                            || $struggle['needs_teacher_attention'];
                    }
                }

                $completedObjectives =
                    collect($objectiveStates)
                        ->filter(
                            fn ($state) =>
                                $state['completed']
                        )
                        ->count();

                $totalObjectives =
                    $objectiveIds->count();

                $progress =
                    $totalObjectives > 0
                        ? round(
                            (
                                $completedObjectives /
                                $totalObjectives
                            ) * 100,
                            1
                        )
                        : 0;

                $started =
                    $studentSessions
                        ->isNotEmpty();

                $completed =
                    $totalObjectives > 0 &&
                    $completedObjectives >=
                    $totalObjectives;

                $lastActivity =
                    $studentSessions
                        ->pluck('last_activity_at')
                        ->filter()
                        ->sortDesc()
                        ->first();

                $index[
                    $student->id
                ][
                    $periodTopic->id
                ] = [
                    'progress' =>
                        $progress,

                    'started' =>
                        $started,

                    'completed' =>
                        $completed,

                    'sessions' =>
                        $studentSessions
                            ->count(),

                    'completed_sessions' =>
                        $studentSessions
                            ->where(
                                'status',
                                'completed'
                            )
                            ->count(),

                    'objectives_total' =>
                        $totalObjectives,

                    'objectives_completed' =>
                        $completedObjectives,

                    'attempts' =>
                        collect(
                            $objectiveStates
                        )->sum('attempts'),

                    'correct_attempts' =>
                        collect(
                            $objectiveStates
                        )->sum(
                            'correct_attempts'
                        ),

                    'attention_objectives' =>
                        collect($objectiveStates)
                            ->where('needs_teacher_attention', true)
                            ->count(),

                    'max_struggle_level' =>
                        (int) collect($objectiveStates)
                            ->max('struggle_level'),

                    'attention_detail' =>
                        collect($objectiveStates)
                            ->filter(fn ($state) => $state['needs_teacher_attention'])
                            ->sortByDesc('struggle_level')
                            ->map(fn ($state) => [
                                'objective' => $state['objective_name'],
                                'reason' => $state['attention_reason'],
                                'misconception' => $state['misconception'],
                                'struggle_level' => $state['struggle_level'],
                            ])
                            ->first(),

                    'topic_name' =>
                        $periodTopic->topic?->topic_name,

                    'last_activity_at' =>
                        $lastActivity,
                ];
            }
        }

        return $index;
    }

    /**
     * ============================================================
     * LEARNING PERIOD ROWS
     * ============================================================
     */
    protected function periodRows(
        Collection $periods,
        Collection $students,
        array $progress,
        array $filters = []
    ): Collection {
        return $periods->map(
            function ($period) use (
                $students,
                $progress
            ) {
                $periodTopics =
                    $period->topics;

                $studentProgress =
                    $students->map(
                        function ($student) use (
                            $periodTopics,
                            $progress
                        ) {
                            $rows =
                                $periodTopics->map(
                                    fn ($periodTopic) =>
                                        $progress[
                                            $student->id
                                        ][
                                            $periodTopic->id
                                        ] ?? [
                                            'progress' => 0,
                                            'started' => false,
                                            'completed' => false,
                                        ]
                                );

                            return $this->average(
                                $rows->pluck(
                                    'progress'
                                )
                            );
                        }
                    );

                $topicProgress =
                    $periodTopics->map(
                        fn ($periodTopic) =>
                            $this->topicProgressFor(
                                $periodTopic,
                                $students,
                                $progress
                            )
                    );

                return [
                    'id' =>
                        $period->id,

                    'title' =>
                        $period->title,

                    'grade_subject_id' =>
                        $period
                            ->grade_subject_id,

                    'group_id' =>
                        $period->group_id,

                    'group_name' =>
                        $period->group
                            ?->group_name,

                    'class_code' =>
                        $period->group
                            ?->class_code,

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

                    'start_date' =>
                        $period
                            ->start_date
                            ?->toDateString(),

                    'end_date' =>
                        $period
                            ->end_date
                            ?->toDateString(),

                    'status' =>
                        $period->status,

                    'topics' =>
                        $periodTopics->count(),

                    'started_topics' =>
                        $topicProgress
                            ->where(
                                'started_learners',
                                '>',
                                0
                            )
                            ->count(),

                    'completed_topics' =>
                        $topicProgress
                            ->filter(
                                fn ($row) =>
                                    $row['students'] > 0 &&
                                    $row['completed_learners'] >=
                                    $row['students']
                            )
                            ->count(),

                    'progress' =>
                        $this->average(
                            $studentProgress
                        ),
                ];
            }
        );
    }

    /**
     * ============================================================
     * TOPIC ROWS
     * ============================================================
     */
    protected function topicRows(
        Collection $periods,
        Collection $students,
        array $progress,
        array $filters = []
    ): Collection {
        return $periods
            ->flatMap(
                function ($period) use (
                    $students,
                    $progress
                ) {
                    return $period
                        ->topics
                        ->map(
                            function (
                                $periodTopic
                            ) use (
                                $period,
                                $students,
                                $progress
                            ) {
                                $row =
                                    $this
                                        ->topicProgressFor(
                                            $periodTopic,
                                            $students,
                                            $progress
                                        );

                                return array_merge(
                                    $row,
                                    [
                                        'learning_period_id' =>
                                            $period->id,

                                        'learning_period_title' =>
                                            $period->title,

                                        'grade_subject_id' =>
                                            $period
                                                ->grade_subject_id,

                                        'group_id' =>
                                            $period->group_id,

                                        'group_name' =>
                                            $period
                                                ->group
                                                ?->group_name,

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
                                    ]
                                );
                            }
                        );
                }
            )
            ->values();
    }

    /**
     * ============================================================
     * TOPIC PROGRESS
     * ============================================================
     */
    protected function topicProgressFor(
        LearningPeriodTopic $periodTopic,
        Collection $students,
        array $progress
    ): array {
        $rows = $students->map(
            fn ($student) =>
                $progress[
                    $student->id
                ][
                    $periodTopic->id
                ] ?? [
                    'progress' => 0,
                    'started' => false,
                    'completed' => false,
                    'sessions' => 0,
                    'objectives_total' => 0,
                    'objectives_completed' => 0,
                ]
        );

        $topic =
            $periodTopic->topic;

        return [
            'learning_period_topic_id' =>
                $periodTopic->id,

            'topic_id' =>
                $topic?->id,

            'topic_name' =>
                $topic?->topic_name,

            'unit_id' =>
                $periodTopic->unit_id,

            'unit_name' =>
                $periodTopic->unit
                    ?->name,

            'progress' =>
                $this->average(
                    $rows->pluck(
                        'progress'
                    )
                ),

            'students' =>
                $students->count(),

            'started_learners' =>
                $rows
                    ->where(
                        'started',
                        true
                    )
                    ->count(),

            'completed_learners' =>
                $rows
                    ->where(
                        'completed',
                        true
                    )
                    ->count(),

            'sessions' =>
                $rows->sum(
                    'sessions'
                ),

            'objectives' =>
                $rows->sum(
                    'objectives_total'
                ),

            'objectives_completed' =>
                $rows->sum(
                    'objectives_completed'
                ),
        ];
    }

    /**
     * ============================================================
     * STUDENT ROWS
     * ============================================================
     */
    protected function studentRows(
        Collection $students,
        Collection $periods,
        array $progress,
        array $filters = []
    ): Collection {
        $periodTopics = $periods
            ->flatMap(
                fn ($period) =>
                    $period->topics
            )
            ->values();

        return $students->map(
            function ($student) use (
                $periodTopics,
                $progress
            ) {
                $rows =
                    $periodTopics->map(
                        fn ($periodTopic) =>
                            $progress[
                                $student->id
                            ][
                                $periodTopic->id
                            ] ?? [
                                'progress' => 0,
                                'started' => false,
                                'completed' => false,
                                'sessions' => 0,
                                'objectives_total' => 0,
                                'objectives_completed' => 0,
                                'attempts' => 0,
                                'correct_attempts' => 0,
                                'attention_objectives' => 0,
                                'max_struggle_level' => 0,
                                'attention_detail' => null,
                                'topic_name' => null,
                                'last_activity_at' => null,
                            ]
                    );

                $lastActivity =
                    $rows
                        ->pluck(
                            'last_activity_at'
                        )
                        ->filter()
                        ->sortDesc()
                        ->first();

                return [
                    'id' =>
                        $student->id,

                    'name' =>
                        $student->name,

                    'email' =>
                        $student->email,

                    'progress' =>
                        $this->average(
                            $rows->pluck(
                                'progress'
                            )
                        ),

                    'topics_available' =>
                        $periodTopics
                            ->count(),

                    'started_topics' =>
                        $rows
                            ->where(
                                'started',
                                true
                            )
                            ->count(),

                    'completed_topics' =>
                        $rows
                            ->where(
                                'completed',
                                true
                            )
                            ->count(),

                    'sessions' =>
                        $rows->sum(
                            'sessions'
                        ),

                    'objectives' =>
                        $rows->sum(
                            'objectives_total'
                        ),

                    'objectives_completed' =>
                        $rows->sum(
                            'objectives_completed'
                        ),

                    'attempts' =>
                        $rows->sum(
                            'attempts'
                        ),

                    'correct_attempts' =>
                        $rows->sum(
                            'correct_attempts'
                        ),

                    'attention_objectives' =>
                        $rows->sum('attention_objectives'),

                    'max_struggle_level' =>
                        (int) $rows->max('max_struggle_level'),

                    'needs_teacher_attention' =>
                        $rows->sum('attention_objectives') > 0,

                    'attention_detail' =>
                        $rows
                            ->filter(fn ($row) => !empty($row['attention_detail']))
                            ->sortByDesc('max_struggle_level')
                            ->map(fn ($row) => [
                                ...$row['attention_detail'],
                                'topic_name' => $row['topic_name'] ?? null,
                            ])
                            ->first(),

                    'last_activity_at' =>
                        $lastActivity
                            ?->toISOString(),
                ];
            }
        );
    }

    protected function objectiveStruggleSummary($sessionObjective): array
    {
        $evaluations = collect($sessionObjective->responseEvaluations ?? []);
        $markedForReview = strtolower((string) ($sessionObjective->status ?? '')) === 'needs_review';

        if ($evaluations->isEmpty()) {
            if ($markedForReview) {
                return [
                    'struggle_level' => 4,
                    'needs_teacher_attention' => true,
                    'attention_reason' => 'The Tutor moved on after persistent difficulty and marked this objective for teacher review.',
                    'misconception' => null,
                ];
            }
            return [
                'struggle_level' => 0,
                'needs_teacher_attention' => false,
                'attention_reason' => null,
                'misconception' => null,
            ];
        }

        $consecutive = 0;

        foreach ($evaluations->sortByDesc('id') as $evaluation) {
            $qualifyingCorrect = strtolower((string) $evaluation->classification) === 'correct'
                && (int) $evaluation->understanding_score >= 80;

            if ($qualifyingCorrect) {
                break;
            }

            $consecutive++;
        }

        if ($consecutive === 0) {
            return [
                'struggle_level' => 0,
                'needs_teacher_attention' => false,
                'attention_reason' => null,
                'misconception' => null,
            ];
        }

        $recent = $evaluations->sortBy('id')->take(-5);
        $average = (int) round($recent->avg('understanding_score') ?? 0);
        $latestMisconception = $recent
            ->pluck('misconception')
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->last();

        $misconceptions = $recent
            ->pluck('misconception')
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->map(fn ($value) => mb_strtolower(trim((string) $value)));

        $hasRepeatedMisconception = $misconceptions
            ->countBy()
            ->contains(fn ($count) => $count >= 2);

        $level = match (true) {
            $consecutive >= 5 => 4,
            $consecutive >= 3,
            ($consecutive >= 2 && $average <= 35),
            $hasRepeatedMisconception => 3,
            $consecutive >= 2 => 2,
            default => 1,
        };

        $reason = match (true) {
            $hasRepeatedMisconception => 'A misconception has persisted across formal learning checks.',
            $consecutive >= 5 => 'Several unsuccessful attempts on the same learning objective.',
            $average <= 35 && $consecutive >= 2 => 'Recent checks show persistently low demonstrated understanding.',
            $consecutive >= 3 => 'Repeated difficulty on the same learning objective.',
            default => null,
        };

        if ($markedForReview) {
            $level = max(4, $level);
            $reason = 'The Tutor moved on after persistent difficulty and marked this objective for teacher review.';
        }

        return [
            'struggle_level' => $level,
            'needs_teacher_attention' => $markedForReview || $level >= 3,
            'attention_reason' => $reason,
            'misconception' => $latestMisconception,
        ];
    }

    /**
     * ============================================================
     * HELPERS
     * ============================================================
     */
    protected function periodRelations(): array
    {
        return [
            'group:id,group_name,class_code',

            'gradeSubject.subject:id,name',

            'gradeSubject.gradeLevel:id,grade_name',

            'topics.topic:id,topic_name,unit_id,grade_subject_id',

            'topics.unit:id,name',
        ];
    }

    protected function subjectRows(
        Collection $gradeSubjects
    ): Collection {
        return $gradeSubjects
            ->map(
                fn ($gradeSubject) => [
                    'id' =>
                        $gradeSubject->id,

                    'subject_id' =>
                        $gradeSubject->subject_id,

                    'subject' =>
                        $gradeSubject->subject
                            ?->name,

                    'grade_level_id' =>
                        $gradeSubject->grade_level_id,

                    'grade' =>
                        $gradeSubject->gradeLevel
                            ?->grade_name,

                    'label' =>
                        trim(
                            ($gradeSubject
                                ->subject
                                ?->name ?? 'Subject')
                            .
                            ' - '
                            .
                            ($gradeSubject
                                ->gradeLevel
                                ?->grade_name ?? 'Grade')
                        ),
                ]
            )
            ->values();
    }

    protected function groupRows(
        Collection $groups
    ): Collection {
        return $groups
            ->map(
                fn ($group) => [
                    'id' =>
                        $group->id,

                    'group_name' =>
                        $group->group_name,

                    'class_code' =>
                        $group->class_code,
                ]
            )
            ->values();
    }

    protected function average(
        Collection $values
    ): float {
        $values = $values
            ->filter(
                fn ($value) =>
                    $value !== null
            )
            ->map(
                fn ($value) =>
                    (float) $value
            );

        if ($values->isEmpty()) {
            return 0.0;
        }

        return round(
            $values->avg(),
            1
        );
    }

    /**
     * ============================================================
     * AUTHORIZATION
     * ============================================================
     */
    protected function ensureTeacherOrAdmin(
        User $teacher
    ): void {
        abort_unless(
            in_array(
                $teacher->role,
                [
                    'teacher',
                    'admin',
                ],
                true
            ),
            403,
            'Only teachers and administrators can view learning analytics.'
        );
    }

    protected function ensureCanViewLearningPeriod(
        User $teacher,
        LearningPeriod $learningPeriod
    ): void {
        $this->ensureTeacherOrAdmin(
            $teacher
        );

        if ($teacher->role === 'admin') {
            if (
                $teacher->school_id &&
                $learningPeriod
                    ->gradeSubject
                    ?->school_id
                &&
                (int) $learningPeriod
                    ->gradeSubject
                    ->school_id !==
                (int) $teacher->school_id
            ) {
                abort(
                    403,
                    'You are not authorized to view this learning period.'
                );
            }

            return;
        }

        $allowed =
            GradeSubject::query()
                ->whereKey(
                    $learningPeriod
                        ->grade_subject_id
                )
                ->where(
                    'teacher_id',
                    $teacher->id
                )
                ->where(
                    'school_id',
                    $teacher->school_id
                )
                ->exists();

        abort_unless(
            $allowed,
            403,
            'You are not authorized to view this learning period.'
        );
    }
}