<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\PracticeAttempt;
use App\Models\StudentAssessment;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeacherLearningActivityController extends Controller
{
    public function index(Request $request)
    {
        $teacher = $request->user();

        $this->ensureTeacher($teacher);

        $validated = $request->validate([
            'group_id' => ['nullable', 'integer'],
        ]);

        $groups = $this->teacherGroups($teacher);

        if (!empty($validated['group_id'])) {
            $groups = $groups->where(
                'id',
                (int) $validated['group_id']
            );
        }

        $studentIds = DB::table('group_students')
            ->whereIn('group_id', $groups->pluck('id'))
            ->pluck('student_id')
            ->unique()
            ->values();

        $students = User::query()
            ->whereIn('id', $studentIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $rows = $students
            ->map(
                fn ($student) =>
                    $this->studentSummary($student, $groups)
            )
            ->values();

        return response()->json([
            'data' => [
                'filters' => [
                    'groups' => $groups
                        ->map(fn ($group) => [
                            'id' => $group->id,
                            'group_name' => $group->group_name,
                            'subject' =>
                                $group->gradeSubject?->subject?->name,
                            'grade' =>
                                $group->gradeSubject?->gradeLevel?->grade_name,
                        ])
                        ->values(),
                ],
                'summary' => [
                    'students' => $rows->count(),
                    'students_with_practice' =>
                        $rows->where('practice_attempts', '>', 0)->count(),
                    'students_with_tutor_activity' =>
                        $rows->where('tutor_sessions', '>', 0)->count(),
                    'low_engagement_students' =>
                        $rows->where('engagement_level', 'low')->count(),
                ],
                'students' => $rows,
            ],
        ]);
    }

    public function student(
        Request $request,
        User $student
    ) {
        $teacher = $request->user();

        $this->ensureTeacher($teacher);

        $groups = $this->teacherGroups($teacher);

        $allowedGroupIds = DB::table('group_students')
            ->where('student_id', $student->id)
            ->whereIn('group_id', $groups->pluck('id'))
            ->pluck('group_id');

        abort_if(
            $allowedGroupIds->isEmpty(),
            403,
            'This student is outside your teaching scope.'
        );

        $groups = $groups->whereIn(
            'id',
            $allowedGroupIds
        );

        $practice = PracticeAttempt::query()
            ->with([
                'group.gradeSubject.subject:id,name',
                'topic:id,topic_name',
            ])
            ->where('student_id', $student->id)
            ->whereIn('group_id', $groups->pluck('id'))
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->get();

        $tutor = TutorSession::query()
            ->where('student_id', $student->id)
            ->whereIn('group_id', $groups->pluck('id'))
            ->get();

        $subjectRows = $groups
            ->groupBy(
                fn ($group) =>
                    $group->gradeSubject?->subject_id ?? 0
            )
            ->map(function ($subjectGroups) use (
                $practice,
                $tutor,
                $student
            ) {
                $subjectId =
                    $subjectGroups
                        ->first()
                        ->gradeSubject
                        ?->subject_id;

                $subjectName =
                    $subjectGroups
                        ->first()
                        ->gradeSubject
                        ?->subject
                        ?->name;

                $groupIds =
                    $subjectGroups->pluck('id');

                $subjectPractice =
                    $practice->whereIn(
                        'group_id',
                        $groupIds
                    );

                $subjectTutor =
                    $tutor->whereIn(
                        'group_id',
                        $groupIds
                    );

                return [
                    'subject' => $subjectName,
                    'assessment_average' =>
                        $this->assessmentAverageForSubject(
                            $student,
                            $subjectId
                        ),
                    'practice_attempts' =>
                        $subjectPractice->count(),
                    'practice_questions' =>
                        (int) $subjectPractice->sum('total_questions'),
                    'practice_accuracy' =>
                        $this->practiceAccuracy($subjectPractice),
                    'practice_minutes' =>
                        $this->practiceMinutes($subjectPractice),
                    'tutor_sessions' =>
                        $subjectTutor->count(),
                    'tutor_messages' =>
                        $this->messageCount($subjectTutor),
                    'tutor_minutes' =>
                        $this->tutorMinutes($subjectTutor),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                ],
                'summary' =>
                    $this->studentSummary($student, $groups),
                'subjects' => $subjectRows,
                'recent_practice' =>
                    $practice
                        ->take(20)
                        ->map(fn ($attempt) => [
                            'id' => $attempt->id,
                            'subject' =>
                                $attempt
                                    ->group
                                    ?->gradeSubject
                                    ?->subject
                                    ?->name,
                            'topic' =>
                                $attempt->topic?->topic_name,
                            'mode' => $attempt->mode,
                            'questions' =>
                                $attempt->total_questions,
                            'correct' =>
                                $attempt->correct_count,
                            'score_percent' =>
                                $attempt->total_questions > 0
                                    ? round(
                                        (
                                            $attempt->correct_count /
                                            $attempt->total_questions
                                        ) * 100,
                                        1
                                    )
                                    : null,
                            'completed_at' =>
                                optional($attempt->completed_at)
                                    ?->toIso8601String(),
                        ])
                        ->values(),
            ],
        ]);
    }

    protected function studentSummary(
        User $student,
        Collection $groups
    ): array {
        $groupIds = $groups->pluck('id');

        $practice = PracticeAttempt::query()
            ->where('student_id', $student->id)
            ->whereIn('group_id', $groupIds)
            ->where('status', 'completed')
            ->get();

        $tutor = TutorSession::query()
            ->where('student_id', $student->id)
            ->whereIn('group_id', $groupIds)
            ->get();

        $assessmentAverage =
            $this->assessmentAverage(
                $student,
                $groups
            );

        $practiceQuestions =
            (int) $practice->sum('total_questions');

        $tutorMessages =
            $this->messageCount($tutor);

        /*
         * Explainable engagement signal.
         * Advisory only: never use it as a grade.
         */
        $interactionScore =
            $practiceQuestions + $tutorMessages;

        $engagement =
            $interactionScore >= 40
                ? 'strong'
                : (
                    $interactionScore >= 15
                        ? 'moderate'
                        : 'low'
                );

        return [
            'student_id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'assessment_average' => $assessmentAverage,
            'practice_attempts' => $practice->count(),
            'practice_questions' => $practiceQuestions,
            'practice_accuracy' =>
                $this->practiceAccuracy($practice),
            'practice_minutes' =>
                $this->practiceMinutes($practice),
            'tutor_sessions' => $tutor->count(),
            'tutor_messages' => $tutorMessages,
            'tutor_minutes' =>
                $this->tutorMinutes($tutor),
            'engagement_level' => $engagement,
            'needs_follow_up' =>
                $assessmentAverage !== null
                && $assessmentAverage < 50
                && $engagement === 'low',
        ];
    }

    protected function teacherGroups(
        User $teacher
    ): Collection {
        $query = Group::query()
            ->with([
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,grade_name',
            ]);

        if ($teacher->role !== 'admin') {
            $query->whereHas(
                'gradeSubject',
                fn ($q) =>
                    $q->where('teacher_id', $teacher->id)
            );
        }

        return $query->get();
    }

    protected function assessmentAverage(
        User $student,
        Collection $groups
    ): ?float {
        $groupIds = $groups->pluck('id');

        $items = StudentAssessment::query()
            ->with('assessment.groups')
            ->where('student_id', $student->id)
            ->where('status', 'completed')
            ->where('max_score', '>', 0)
            ->get()
            ->filter(
                fn ($item) =>
                    $item
                        ->assessment
                        ?->groups
                        ?->pluck('id')
                        ->intersect($groupIds)
                        ->isNotEmpty()
                    ?? false
            );

        if ($items->isEmpty()) {
            return null;
        }

        return round(
            $items->avg(
                fn ($item) =>
                    (
                        (float) $item->score /
                        (float) $item->max_score
                    ) * 100
            ),
            1
        );
    }

    protected function assessmentAverageForSubject(
        User $student,
        ?int $subjectId
    ): ?float {
        if (!$subjectId) {
            return null;
        }

        $items = StudentAssessment::query()
            ->with('assessment.gradeSubject')
            ->where('student_id', $student->id)
            ->where('status', 'completed')
            ->where('max_score', '>', 0)
            ->get()
            ->filter(
                fn ($item) =>
                    (int)
                    $item->assessment?->gradeSubject?->subject_id
                    === (int) $subjectId
            );

        if ($items->isEmpty()) {
            return null;
        }

        return round(
            $items->avg(
                fn ($item) =>
                    (
                        (float) $item->score /
                        (float) $item->max_score
                    ) * 100
            ),
            1
        );
    }

    protected function practiceAccuracy(
        Collection $attempts
    ): ?float {
        $questions =
            (int) $attempts->sum('total_questions');

        if ($questions === 0) {
            return null;
        }

        return round(
            (
                $attempts->sum('correct_count') /
                $questions
            ) * 100,
            1
        );
    }

    protected function practiceMinutes(
        Collection $attempts
    ): int {
        return (int) $attempts->sum(
            function ($attempt) {
                if (
                    !$attempt->started_at ||
                    !$attempt->completed_at
                ) {
                    return 0;
                }

                return max(
                    1,
                    $attempt
                        ->started_at
                        ->diffInMinutes($attempt->completed_at)
                );
            }
        );
    }

    protected function tutorMinutes(
        Collection $sessions
    ): int {
        return (int) $sessions->sum(
            function ($session) {
                if (
                    !$session->started_at ||
                    !$session->last_activity_at
                ) {
                    return 0;
                }

                return max(
                    1,
                    $session
                        ->started_at
                        ->diffInMinutes($session->last_activity_at)
                );
            }
        );
    }

    protected function messageCount(
        Collection $sessions
    ): int {
        if ($sessions->isEmpty()) {
            return 0;
        }

        return DB::table('tutor_messages')
            ->whereIn(
                'tutor_session_id',
                $sessions->pluck('id')
            )
            ->where('role', 'user')
            ->count();
    }

    protected function ensureTeacher(User $user): void
    {
        abort_unless(
            in_array(
                $user->role,
                ['teacher', 'admin'],
                true
            ),
            403
        );
    }
}
