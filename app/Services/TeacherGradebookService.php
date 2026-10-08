<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Group;
use App\Models\StudentAssessment;
use App\Models\StudentAnswer;
use App\Models\TutorResponseEvaluation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TeacherGradebookService
{
    public function overview(User $teacher, array $filters = []): array
    {
        $this->ensureTeacherOrAdmin($teacher);

        $groups = $this->accessibleGroups($teacher, $filters);
        $groupIds = $groups->pluck('id')->all();

        $students = $groups
            ->flatMap(fn (Group $group) => $group->students)
            ->unique('id')
            ->sortBy('name')
            ->values();

        $assessments = $this->assessmentColumns($groupIds, $filters);
        $checks = $this->understandingChecks($groupIds, $filters);

        $studentRows = $this->studentRows(
            $students,
            $groups,
            $assessments,
            $checks
        );

        $objectiveRows = $this->objectiveRows($checks);

        return [
            'filters' => [
                'group_id' => $filters['group_id'] ?? null,
                'grade_subject_id' => $filters['grade_subject_id'] ?? null,
                'assessment_type' => $filters['assessment_type'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],

            'available_filters' => [
                'groups' => $groups->map(fn (Group $group) => [
                    'id' => $group->id,
                    'name' => $group->group_name,
                    'academic_year' => $group->academic_year,
                    'grade_subject_id' => $group->grade_subject_id,
                    'subject' => $group->gradeSubject?->subject?->name,
                    'grade' => $group->gradeSubject?->gradeLevel?->grade_name,
                ])->values(),

                'teaching_areas' => $groups
                    ->filter(fn (Group $group) => $group->gradeSubject)
                    ->map(fn (Group $group) => [
                        'id' => $group->gradeSubject->id,
                        'label' => trim(implode(' - ', array_filter([
                            $group->gradeSubject?->subject?->name,
                            $group->gradeSubject?->gradeLevel?->grade_name,
                        ]))),
                    ])
                    ->unique('id')
                    ->values(),

                'assessment_types' => [
                    ['value' => 'quiz', 'label' => 'Quiz'],
                    ['value' => 'exam', 'label' => 'Exam'],
                    ['value' => 'homework', 'label' => 'Homework'],
                    ['value' => 'practice', 'label' => 'Practice'],
                ],
            ],

            'summary' => [
                'students' => $studentRows->count(),
                'assessments' => $assessments->count(),
                'understanding_checks' => $checks->count(),
                'assessment_average' => $this->average(
                    $studentRows->pluck('assessment_average')->filter(fn ($value) => $value !== null)
                ),
                'check_average' => $this->average(
                    $studentRows->pluck('check_average')->filter(fn ($value) => $value !== null)
                ),
                'students_needing_attention' => $studentRows
                    ->filter(fn ($row) => $row['needs_attention'])
                    ->count(),
            ],

            'assessment_columns' => $assessments->values()->all(),
            'students' => $studentRows->values()->all(),
            'understanding_checks' => $checks->values()->all(),
            'objectives' => $objectiveRows->values()->all(),
        ];
    }

    public function exportRows(User $teacher, array $filters = []): array
    {
        $data = $this->overview($teacher, $filters);

        $columns = collect($data['assessment_columns']);

        $headings = [
            'Student',
            'Email',
            'Class(es)',
        ];

        foreach ($columns as $column) {
            $headings[] = $column['title'] . ' (' . $column['group_name'] . ')';
        }

        $headings[] = 'Assessment Average %';
        $headings[] = 'Understanding Check Average %';
        $headings[] = 'Understanding Checks Completed';
        $headings[] = 'Needs Attention';

        $rows = collect($data['students'])->map(function ($student) use ($columns) {
            $row = [
                $student['name'],
                $student['email'],
                implode(', ', $student['classes']),
            ];

            foreach ($columns as $column) {
                $score = $student['assessment_scores'][$column['key']] ?? null;

                if (!$score || $score['percentage'] === null) {
                    $row[] = '';
                    continue;
                }

                $row[] = $score['percentage'];
            }

            $row[] = $student['assessment_average'] ?? '';
            $row[] = $student['check_average'] ?? '';
            $row[] = $student['check_count'];
            $row[] = $student['needs_attention'] ? 'Yes' : 'No';

            return $row;
        })->all();

        return [
            'headings' => $headings,
            'rows' => $rows,
        ];
    }

    protected function accessibleGroups(User $teacher, array $filters = []): Collection
    {
        $query = Group::query()
            ->with([
                'students:id,name,email',
                'gradeSubject:id,subject_id,grade_level_id',
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
                'creator:id,school_id',
            ]);

        if ($teacher->role === 'admin') {
            $query->where(function ($q) use ($teacher) {
                $q->whereHas('creator', function ($creator) use ($teacher) {
                    $creator->where('school_id', $teacher->school_id);
                })->orWhereHas('gradeSubject', function ($gradeSubject) use ($teacher) {
                    $gradeSubject->where('school_id', $teacher->school_id);
                });
            });
        } else {
            $query->where('created_by', $teacher->id);
        }

        if (!empty($filters['group_id'])) {
            $query->whereKey((int) $filters['group_id']);
        }

        if (!empty($filters['grade_subject_id'])) {
            $query->where('grade_subject_id', (int) $filters['grade_subject_id']);
        }

        return $query
            ->orderBy('group_name')
            ->get();
    }

    protected function assessmentColumns(array $groupIds, array $filters = []): Collection
    {
        if (empty($groupIds)) {
            return collect();
        }

        $query = Assessment::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
            ->with([
                'groups' => fn ($q) => $q
                    ->whereIn('groups.id', $groupIds)
                    ->select('groups.id', 'group_name', 'grade_subject_id', 'academic_year'),
                'assessmentQuestions.question:id,marks',
                'studentAssessments:id,student_id,assessment_id,score,max_score,status,completed_at',
            ])
            ->orderByDesc('created_at');

        if (!empty($filters['assessment_type'])) {
            $query->where('type', $filters['assessment_type']);
        }

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $assessments = $query->get();

        return $assessments->flatMap(function (Assessment $assessment) {
            $questionMax = $assessment->assessmentQuestions
                ->sum(fn ($aq) => (float) ($aq->question?->marks ?? 0));

            return $assessment->groups->map(function ($group) use ($assessment, $questionMax) {
                $attempts = $assessment->studentAssessments
                    ->keyBy('student_id');

                return [
                    'key' => $assessment->id . '-' . $group->id,
                    'assessment_id' => $assessment->id,
                    'group_id' => $group->id,
                    'group_name' => $group->group_name,
                    'title' => $assessment->title,
                    'type' => $assessment->type,
                    'delivery_mode' => $assessment->delivery_mode,
                    'due_date' => $assessment->due_date ? (string) $assessment->due_date : null,
                    'max_score' => round($questionMax, 2),
                    'attempts' => $attempts,
                ];
            });
        })->values();
    }

    protected function understandingChecks(array $groupIds, array $filters = []): Collection
    {
        if (empty($groupIds)) {
            return collect();
        }

        $query = TutorResponseEvaluation::query()
            ->whereHas('session', fn ($q) => $q->whereIn('group_id', $groupIds))
            ->with([
                'student:id,name,email',
                'session:id,group_id,topic_id,learning_period_id',
                'session.group:id,group_name',
                'session.topic:id,topic_name,unit_id',
                'session.topic.unit:id,title',
                'sessionObjective:id,learning_objective_id,tutor_session_id',
                'sessionObjective.learningObjective:id,topic_id,code,objective',
            ])
            ->orderByDesc('created_at');

        if (!empty($filters['group_id'])) {
            $groupId = (int) $filters['group_id'];
            $query->whereHas('session', fn ($q) => $q->where('group_id', $groupId));
        }

        if (!empty($filters['grade_subject_id'])) {
            $gradeSubjectId = (int) $filters['grade_subject_id'];
            $query->whereHas('session', fn ($q) => $q->where('grade_subject_id', $gradeSubjectId));
        }

        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->get()->map(function (TutorResponseEvaluation $evaluation) {
            $objective = $evaluation->sessionObjective?->learningObjective;
            $topic = $evaluation->session?->topic;

            return [
                'id' => $evaluation->id,
                'student_id' => $evaluation->student_id,
                'student_name' => $evaluation->student?->name,
                'group_id' => $evaluation->session?->group_id,
                'group_name' => $evaluation->session?->group?->group_name,
                'unit' => $topic?->unit?->title,
                'topic' => $topic?->topic_name,
                'learning_objective_id' => $objective?->id,
                'objective_code' => $objective?->code,
                'objective' => $objective?->objective,
                'classification' => $evaluation->classification,
                'correct' => (bool) $evaluation->correct,
                'score' => (int) $evaluation->understanding_score,
                'misconception' => $evaluation->misconception,
                'created_at' => optional($evaluation->created_at)->toDateTimeString(),
            ];
        })->values();
    }

    protected function studentRows(
        Collection $students,
        Collection $groups,
        Collection $assessments,
        Collection $checks
    ): Collection {
        $studentGroupMap = [];

        foreach ($groups as $group) {
            foreach ($group->students as $student) {
                $studentGroupMap[$student->id][] = [
                    'id' => $group->id,
                    'name' => $group->group_name,
                ];
            }
        }

        return $students->map(function (User $student) use (
            $studentGroupMap,
            $assessments,
            $checks
        ) {
            $classes = collect($studentGroupMap[$student->id] ?? []);
            $classIds = $classes->pluck('id')->all();

            $assessmentScores = [];
            $assessmentPercentages = collect();

            foreach ($assessments as $column) {
                if (!in_array($column['group_id'], $classIds, true)) {
                    continue;
                }

                /** @var StudentAssessment|null $attempt */
                $attempt = $column['attempts']->get($student->id);

                if (!$attempt) {
                    $assessmentScores[$column['key']] = [
                        'status' => 'not_started',
                        'score' => null,
                        'max_score' => $column['max_score'],
                        'percentage' => null,
                    ];
                    continue;
                }

                $maxScore = (float) $attempt->max_score > 0
                    ? (float) $attempt->max_score
                    : (float) $column['max_score'];

                $percentage = $maxScore > 0
                    ? round(((float) $attempt->score / $maxScore) * 100, 1)
                    : null;

                $assessmentScores[$column['key']] = [
                    'status' => $attempt->status,
                    'score' => round((float) $attempt->score, 2),
                    'max_score' => round($maxScore, 2),
                    'percentage' => $percentage,
                    'completed_at' => optional($attempt->completed_at)->toDateTimeString(),
                ];

                if ($percentage !== null && in_array($attempt->status, ['graded', 'completed', 'under_review'], true)) {
                    $assessmentPercentages->push($percentage);
                }
            }

            $studentChecks = $checks
                ->where('student_id', $student->id)
                ->values();

            $checkAverage = $this->average($studentChecks->pluck('score'));
            $assessmentAverage = $this->average($assessmentPercentages);

            $latestCheck = $studentChecks->first();

            $needsAttention = (
                $checkAverage !== null && $checkAverage < 50
            ) || (
                $assessmentAverage !== null && $assessmentAverage < 50
            ) || $studentChecks->contains(fn ($check) => !empty($check['misconception']));

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'classes' => $classes->pluck('name')->values()->all(),
                'assessment_scores' => $assessmentScores,
                'assessment_average' => $assessmentAverage,
                'assessment_count' => $assessmentPercentages->count(),
                'check_average' => $checkAverage,
                'check_count' => $studentChecks->count(),
                'latest_check_score' => $latestCheck['score'] ?? null,
                'latest_check_at' => $latestCheck['created_at'] ?? null,
                'needs_attention' => $needsAttention,
            ];
        });
    }

    protected function objectiveRows(Collection $checks): Collection
    {
        return $checks
            ->filter(fn ($check) => !empty($check['learning_objective_id']))
            ->groupBy('learning_objective_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $scores = $rows->pluck('score');

                return [
                    'learning_objective_id' => $first['learning_objective_id'],
                    'code' => $first['objective_code'],
                    'objective' => $first['objective'],
                    'topic' => $first['topic'],
                    'unit' => $first['unit'],
                    'attempts' => $rows->count(),
                    'students' => $rows->pluck('student_id')->unique()->count(),
                    'average_score' => $this->average($scores),
                    'mastery_rate' => $rows->count() > 0
                        ? round(($rows->where('score', '>=', 80)->count() / $rows->count()) * 100, 1)
                        : null,
                ];
            })
            ->sortBy('unit')
            ->sortBy('topic')
            ->values();
    }



    public function studentProfile(User $teacher, User $student, array $filters = []): array
    {
        $this->ensureTeacherOrAdmin($teacher);

        $groups = $this->accessibleGroups($teacher, $filters)
            ->filter(fn (Group $group) => $group->students->contains('id', $student->id))
            ->values();

        abort_if(
            $groups->isEmpty(),
            403,
            'This student is not enrolled in a class you can manage.'
        );

        $groupIds = $groups->pluck('id')->all();

        $attemptQuery = StudentAssessment::query()
            ->where('student_id', $student->id)
            ->whereHas('assessment.groups', fn ($q) => $q->whereIn('groups.id', $groupIds));

        if (!empty($filters['assessment_type'])) {
            $attemptQuery->whereHas('assessment', fn ($q) =>
                $q->where('type', $filters['assessment_type'])
            );
        }

        if (!empty($filters['from'])) {
            $attemptQuery->whereDate('completed_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $attemptQuery->whereDate('completed_at', '<=', $filters['to']);
        }

        $attempts = $attemptQuery
            ->with([
                'assessment:id,title,type,creator_id,due_date,delivery_mode,status',
                'assessment.groups' => fn ($q) => $q
                    ->whereIn('groups.id', $groupIds)
                    ->select('groups.id', 'group_name', 'grade_subject_id', 'academic_year'),
                'assessment.assessmentQuestions.question:id,marks',
                'studentAnswers.question:id,question_type,question,marks,correct_answer,explanation,question_image,correct_answer_image,learning_objective_id',
                'studentAnswers.question.learningObjective:id,code,objective',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('assigned_at')
            ->get()
            ->map(function (StudentAssessment $attempt) {
                $fallbackMax = $attempt->assessment?->assessmentQuestions
                    ?->sum(fn ($aq) => (float) ($aq->question?->marks ?? 0)) ?? 0;

                $maxScore = (float) $attempt->max_score > 0
                    ? (float) $attempt->max_score
                    : (float) $fallbackMax;

                $percentage = $maxScore > 0
                    ? round(((float) $attempt->score / $maxScore) * 100, 1)
                    : null;

                return [
                    'id' => $attempt->id,
                    'assessment_id' => $attempt->assessment_id,
                    'title' => $attempt->assessment?->title,
                    'type' => $attempt->assessment?->type,
                    'delivery_mode' => $attempt->assessment?->delivery_mode,
                    'status' => $attempt->status,
                    'score' => round((float) $attempt->score, 2),
                    'max_score' => round($maxScore, 2),
                    'percentage' => $percentage,
                    'assigned_at' => optional($attempt->assigned_at)->toDateTimeString(),
                    'completed_at' => optional($attempt->completed_at)->toDateTimeString(),
                    'classes' => $attempt->assessment?->groups?->pluck('group_name')->values()->all() ?? [],
                    'answers' => $attempt->studentAnswers->map(function (StudentAnswer $answer) {
                        $question = $answer->question;

                        return [
                            'id' => $answer->id,
                            'question_id' => $answer->question_id,
                            'question_type' => $question?->question_type,
                            'question' => $question?->question,
                            'question_image_url' => $question?->question_image_url,
                            'answer' => $answer->answer,
                            'points_earned' => (float) ($answer->points_earned ?? 0),
                            'marks' => (float) ($question?->marks ?? 0),
                            'is_correct' => $answer->is_correct,
                            'reviewed_at' => optional($answer->reviewed_at)->toDateTimeString(),
                            'teacher_feedback' => $answer->teacher_feedback,
                            'objective_code' => $question?->learningObjective?->code,
                            'objective' => $question?->learningObjective?->objective,
                        ];
                    })->values()->all(),
                ];
            });

        $checks = $this->understandingChecks($groupIds, $filters)
            ->where('student_id', $student->id)
            ->values();

        $assessmentPercentages = $attempts
            ->pluck('percentage')
            ->filter(fn ($value) => $value !== null);

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'classes' => $groups->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->group_name,
                'academic_year' => $group->academic_year,
                'subject' => $group->gradeSubject?->subject?->name,
                'grade' => $group->gradeSubject?->gradeLevel?->grade_name,
            ])->values()->all(),
            'summary' => [
                'assessment_average' => $this->average($assessmentPercentages),
                'assessments_completed' => $attempts
                    ->filter(fn ($row) => in_array($row['status'], ['graded', 'completed', 'under_review'], true))
                    ->count(),
                'check_average' => $this->average($checks->pluck('score')),
                'checks_completed' => $checks->count(),
                'misconceptions' => $checks->filter(fn ($row) => !empty($row['misconception']))->count(),
            ],
            'assessments' => $attempts->values()->all(),
            'understanding_checks' => $checks->values()->all(),
        ];
    }

    public function manualReviewQueue(User $teacher, array $filters = []): Collection
    {
        $this->ensureTeacherOrAdmin($teacher);

        $groups = $this->accessibleGroups($teacher, $filters);
        $groupIds = $groups->pluck('id')->all();

        if (empty($groupIds)) {
            return collect();
        }

        $query = StudentAnswer::query()
            ->whereNull('reviewed_at')
            ->whereHas('question', fn ($q) => $q->where('question_type', 'open_ended'))
            ->whereHas('studentAssessment', function ($q) use ($groupIds, $filters) {
                $q->where('status', 'under_review')
                    ->whereHas('assessment.groups', fn ($g) => $g->whereIn('groups.id', $groupIds));

                if (!empty($filters['assessment_type'])) {
                    $q->whereHas('assessment', fn ($assessment) =>
                        $assessment->where('type', $filters['assessment_type'])
                    );
                }

                if (!empty($filters['from'])) {
                    $q->whereDate('completed_at', '>=', $filters['from']);
                }

                if (!empty($filters['to'])) {
                    $q->whereDate('completed_at', '<=', $filters['to']);
                }
            });

        return $query
            ->with([
                'question:id,question_type,question,marks,question_image,learning_objective_id',
                'question.learningObjective:id,code,objective',
                'studentAssessment:id,student_id,assessment_id,status,score,max_score,completed_at',
                'studentAssessment.student:id,name,email',
                'studentAssessment.assessment:id,title,type,creator_id',
                'studentAssessment.assessment.groups' => fn ($q) => $q
                    ->whereIn('groups.id', $groupIds)
                    ->select('groups.id', 'group_name'),
            ])
            ->orderBy('student_assessment_id')
            ->orderBy('id')
            ->get()
            ->map(function (StudentAnswer $answer) {
                $question = $answer->question;
                $attempt = $answer->studentAssessment;

                return [
                    'answer_id' => $answer->id,
                    'student_assessment_id' => $attempt?->id,
                    'student_id' => $attempt?->student_id,
                    'student_name' => $attempt?->student?->name,
                    'student_email' => $attempt?->student?->email,
                    'assessment_id' => $attempt?->assessment_id,
                    'assessment_title' => $attempt?->assessment?->title,
                    'assessment_type' => $attempt?->assessment?->type,
                    'classes' => $attempt?->assessment?->groups?->pluck('group_name')->values()->all() ?? [],
                    'question_id' => $answer->question_id,
                    'question' => $question?->question,
                    'question_image_url' => $question?->question_image_url,
                    'answer' => $answer->answer,
                    'marks' => (float) ($question?->marks ?? 0),
                    'objective_code' => $question?->learningObjective?->code,
                    'objective' => $question?->learningObjective?->objective,
                    'completed_at' => optional($attempt?->completed_at)->toDateTimeString(),
                ];
            })
            ->values();
    }

    public function reviewAnswer(
        User $teacher,
        StudentAnswer $answer,
        float $points,
        ?string $feedback = null
    ): array {
        $this->ensureTeacherOrAdmin($teacher);

        $answer->loadMissing([
            'question:id,question_type,marks',
            'studentAssessment.assessment.groups:id,group_name,created_by,grade_subject_id',
        ]);

        abort_unless(
            $answer->question?->question_type === 'open_ended',
            422,
            'Only open-ended answers are manually graded in this queue.'
        );

        $accessibleGroupIds = $this->accessibleGroups($teacher)
            ->pluck('id');

        $answerGroupIds = $answer->studentAssessment?->assessment?->groups
            ?->pluck('id') ?? collect();

        abort_if(
            $answerGroupIds->intersect($accessibleGroupIds)->isEmpty(),
            403,
            'You are not authorized to grade this response.'
        );

        $maxMarks = (float) ($answer->question?->marks ?? 0);

        abort_if(
            $points < 0 || $points > $maxMarks,
            422,
            'Points must be between 0 and ' . $maxMarks . '.'
        );

        $answer->update([
            'points_earned' => $points,
            'is_correct' => $maxMarks > 0 && $points >= $maxMarks,
            'reviewed_by' => $teacher->id,
            'reviewed_at' => now(),
            'teacher_feedback' => $feedback ? trim($feedback) : null,
        ]);

        $attempt = $answer->studentAssessment()->with('assessment.assessmentQuestions.question')->firstOrFail();

        $remaining = $attempt->studentAnswers()
            ->whereNull('reviewed_at')
            ->whereHas('question', fn ($q) => $q->where('question_type', 'open_ended'))
            ->count();

        $score = (float) $attempt->studentAnswers()->sum('points_earned');
        $maxScore = (float) $attempt->assessment->assessmentQuestions
            ->sum(fn ($aq) => (float) ($aq->question?->marks ?? 0));

        $attempt->update([
            'score' => $score,
            'max_score' => $maxScore,
            'status' => $remaining === 0 ? 'graded' : 'under_review',
        ]);

        return [
            'answer_id' => $answer->id,
            'points_earned' => (float) $answer->points_earned,
            'reviewed_at' => optional($answer->reviewed_at)->toDateTimeString(),
            'student_assessment_id' => $attempt->id,
            'assessment_status' => $attempt->status,
            'assessment_score' => (float) $attempt->score,
            'assessment_max_score' => (float) $attempt->max_score,
            'remaining_manual_answers' => $remaining,
        ];
    }

    protected function average(Collection $values): ?float
    {
        $filtered = $values->filter(fn ($value) => $value !== null && $value !== '');

        return $filtered->isEmpty()
            ? null
            : round((float) $filtered->avg(), 1);
    }

    protected function ensureTeacherOrAdmin(User $user): void
    {
        abort_unless(
            in_array($user->role, ['teacher', 'admin'], true),
            403,
            'Only teachers and administrators can access the gradebook.'
        );
    }
}
