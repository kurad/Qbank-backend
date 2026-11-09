<?php

namespace App\Http\Controllers;

use App\Models\LearningCheckIn;
use App\Models\LearningPeriod;
use App\Models\LearningSupportThread;
use App\Models\LearningObjective;
use App\Models\LearningSupportMessage;
use App\Models\StudentTopicLearningState;
use App\Models\Topic;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningSupportController extends Controller
{
    public function studentSummary(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user && $user->role === 'student',
            403
        );

        $unreadQuery = LearningSupportMessage::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->whereHas('thread', function ($query) use ($user) {
                $query->where('student_id', $user->id);
            });

        $unreadCount = (clone $unreadQuery)->count();

        $latest = $unreadQuery
            ->with([
                'sender:id,name,role',
                'thread:id,student_id,teacher_id,learning_period_id,topic_id,learning_objective_id,type,status,subject,last_message_at',
                'thread.teacher:id,name',
                'thread.learningPeriod:id,title,group_id',
                'thread.topic:id,topic_name',
                'thread.learningObjective:id,topic_id,code,objective,description',
            ])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function (LearningSupportMessage $message) {
                $thread = $message->thread;

                return [
                    'id' => $message->id,
                    'thread_id' => $message->thread_id,
                    'message' => $message->message,
                    'created_at' => optional($message->created_at)?->toIso8601String(),
                    'teacher' => [
                        'id' => $message->sender?->id,
                        'name' =>
                            $message->sender?->name
                            ?? $thread?->teacher?->name
                            ?? 'Teacher',
                    ],
                    'thread' => [
                        'type' => $thread?->type,
                        'status' => $thread?->status,
                        'subject' => $thread?->subject,
                        'learning_period_id' => $thread?->learning_period_id,
                        'learning_period' => $thread?->learningPeriod?->title,
                        'topic_id' => $thread?->topic_id,
                        'topic' => $thread?->topic?->topic_name,
                        'group_id' => $thread?->learningPeriod?->group_id,
                        'learning_objective_id' => $thread?->learning_objective_id,
                        'learning_objective' => $thread?->learningObjective
                            ? [
                                'id' => $thread->learningObjective->id,
                                'code' => $thread->learningObjective->code,
                                'objective' => $thread->learningObjective->objective,
                                'description' => $thread->learningObjective->description,
                            ]
                            : null,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'unread_count' => $unreadCount,
                'latest_unread' => $latest,
            ],
        ]);
    }

    public function studentThreads(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'student', 403);

        /*
         * Opening Teacher Support is the student's explicit read action.
         * Mark only teacher-to-student messages as read. Student replies do
         * not participate in the student's unread badge.
         */
        LearningSupportMessage::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->whereHas('thread', function ($query) use ($user) {
                $query->where('student_id', $user->id);
            })
            ->update(['read_at' => now()]);

        $threads = LearningSupportThread::query()
            ->where('student_id', $user->id)
            ->with([
                'teacher:id,name,email',
                'learningPeriod:id,title,group_id',
                'topic:id,topic_name',
                'learningObjective:id,topic_id,code,objective,description',
                'messages.sender:id,name,role',
                'checkIns',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $threads]);
    }

    public function teacherNeedsReview(Request $request)
    {
        $teacher = $this->teacher($request);

        $periodIds = LearningPeriod::query()
            ->when(
                $teacher->role !== 'admin',
                fn ($query) => $query->where(
                    'created_by',
                    $teacher->id
                )
            )
            ->pluck('id');

        $rows = StudentTopicLearningState::query()
            ->whereIn('learning_period_id', $periodIds)
            ->where('mastery_status', 'needs_review')
            ->with([
                'student:id,name,email',
                'learningPeriod:id,title,group_id,created_by',
                'learningPeriod.group:id,group_name',
                'topic:id,topic_name',
            ])
            ->orderByDesc('last_attempt_at')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['data' => []]);
        }

        /*
         * Pull the actual Tutor evidence behind each topic-level
         * needs-review state. This avoids presenting only the topic's
         * aggregate attempt_count / best_score, which can be misleading
         * when one objective remains unresolved after earlier success.
         */
        $sessions = TutorSession::query()
            ->whereIn(
                'student_id',
                $rows->pluck('student_id')->unique()
            )
            ->whereIn(
                'learning_period_id',
                $rows->pluck('learning_period_id')->unique()
            )
            ->whereIn(
                'topic_id',
                $rows->pluck('topic_id')->unique()
            )
            ->with([
                'objectives' => function ($query) {
                    $query
                        ->with([
                            'learningObjective:id,topic_id,code,objective,description,order',
                            'responseEvaluations' => function ($query) {
                                $query
                                    ->orderBy('id')
                                    ->select([
                                        'id',
                                        'tutor_session_id',
                                        'tutor_session_objective_id',
                                        'student_id',
                                        'student_response',
                                        'classification',
                                        'correct',
                                        'understanding_score',
                                        'feedback',
                                        'evidence',
                                        'misconception',
                                        'created_at',
                                    ]);
                            },
                        ])
                        ->orderBy('objective_order');
                },
            ])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();

        $sessionGroups = $sessions->groupBy(
            fn ($session) =>
                $session->student_id
                . ':'
                . $session->learning_period_id
                . ':'
                . $session->topic_id
        );

        $data = $rows->map(function ($state) use ($sessionGroups) {
            $key =
                $state->student_id
                . ':'
                . $state->learning_period_id
                . ':'
                . $state->topic_id;

            $topicSessions = collect(
                $sessionGroups->get($key, collect())
            );

            $diagnostic = $this->buildStruggleDiagnostic(
                $topicSessions,
                $state
            );

            $state->setAttribute(
                'struggle_diagnostic',
                $diagnostic
            );

            return $state;
        });

        return response()->json(['data' => $data]);
    }

    protected function buildStruggleDiagnostic(
        $sessions,
        StudentTopicLearningState $state
    ): array {
        $objectives = collect($sessions)
            ->flatMap(fn ($session) => $session->objectives)
            ->groupBy('learning_objective_id')
            ->map(function ($rows) {
                $orderedObjectives = $rows
                    ->sortBy('id')
                    ->values();

                $latestObjective = $orderedObjectives->last();

                $evaluations = $orderedObjectives
                    ->flatMap(
                        fn ($objective) =>
                            $objective->responseEvaluations
                    )
                    ->sortBy('id')
                    ->values();

                $recentEvaluations = $evaluations
                    ->take(-5)
                    ->values();

                $recentScores = $recentEvaluations
                    ->pluck('understanding_score')
                    ->filter(
                        fn ($value) =>
                            $value !== null
                    );

                $misconceptions = $recentEvaluations
                    ->pluck('misconception')
                    ->filter(
                        fn ($value) =>
                            trim((string) $value) !== ''
                    )
                    ->map(
                        fn ($value) =>
                            trim((string) $value)
                    )
                    ->unique()
                    ->values();

                $latestFeedback = $recentEvaluations
                    ->pluck('feedback')
                    ->filter(
                        fn ($value) =>
                            trim((string) $value) !== ''
                    )
                    ->last();

                $latestEvidence = $recentEvaluations
                    ->pluck('evidence')
                    ->filter(
                        fn ($value) =>
                            trim((string) $value) !== ''
                    )
                    ->last();

                $latestMisconception = $misconceptions->last();

                $recentChecks = $recentEvaluations
                    ->take(-3)
                    ->map(function ($evaluation) {
                        return [
                            'id' => $evaluation->id,
                            'classification' =>
                                $evaluation->classification,
                            'correct' =>
                                (bool) $evaluation->correct,
                            'understanding_score' =>
                                (int) (
                                    $evaluation
                                        ->understanding_score
                                    ?? 0
                                ),
                            'student_response' =>
                                $evaluation->student_response,
                            'feedback' =>
                                $evaluation->feedback,
                            'evidence' =>
                                $evaluation->evidence,
                            'misconception' =>
                                $evaluation->misconception,
                            'created_at' =>
                                optional(
                                    $evaluation->created_at
                                )?->toIso8601String(),
                        ];
                    })
                    ->values();

                $objectiveStatus = strtolower(
                    (string) (
                        $latestObjective?->status
                        ?? 'not_started'
                    )
                );

                return [
                    'learning_objective_id' =>
                        $latestObjective
                            ?->learning_objective_id,
                    'objective' =>
                        $latestObjective
                            ?->learningObjective
                            ?->objective
                        ?? 'Learning objective',
                    'objective_code' =>
                        $latestObjective
                            ?->learningObjective
                            ?->code,
                    'description' =>
                        $latestObjective
                            ?->learningObjective
                            ?->description,
                    'status' =>
                        $objectiveStatus,
                    'needs_review' =>
                        $objectiveStatus ===
                        'needs_review',
                    'attempts' =>
                        max(
                            (int) (
                                $latestObjective
                                    ?->attempts
                                ?? 0
                            ),
                            $evaluations->count()
                        ),
                    'correct_attempts' =>
                        (int) (
                            $latestObjective
                                ?->correct_attempts
                            ?? 0
                        ),
                    'evaluation_count' =>
                        $evaluations->count(),
                    'recent_average_score' =>
                        $recentScores->isNotEmpty()
                            ? (int) round(
                                $recentScores->avg()
                            )
                            : null,
                    'latest_score' =>
                        $recentScores->isNotEmpty()
                            ? (int) $recentScores->last()
                            : null,
                    'latest_classification' =>
                        $recentEvaluations
                            ->last()
                            ?->classification,
                    'latest_misconception' =>
                        $latestMisconception,
                    'misconceptions' =>
                        $misconceptions
                            ->take(-3)
                            ->values()
                            ->all(),
                    'latest_feedback' =>
                        $latestFeedback,
                    'latest_evidence' =>
                        $latestEvidence,
                    'recent_checks' =>
                        $recentChecks->all(),
                ];
            })
            ->values();

        $needsReviewObjectives = $objectives
            ->where('needs_review', true)
            ->values();

        /*
         * In rare older data, the topic state may be needs_review while
         * objective rows were not updated correctly. Fall back to the
         * most recently attempted objective so the teacher still sees
         * useful evidence instead of a blank diagnostic.
         */
        $focusObjectives =
            $needsReviewObjectives->isNotEmpty()
                ? $needsReviewObjectives
                : $objectives
                    ->filter(
                        fn ($objective) =>
                            ($objective['evaluation_count'] ?? 0)
                            > 0
                    )
                    ->sortByDesc('evaluation_count')
                    ->take(2)
                    ->values();

        $allEvaluations = $objectives
            ->flatMap(
                fn ($objective) =>
                    $objective['recent_checks'] ?? []
            );

        $unsuccessfulChecks = $allEvaluations
            ->filter(function ($evaluation) {
                return !(
                    ($evaluation['correct'] ?? false)
                    && (
                        (int) (
                            $evaluation[
                                'understanding_score'
                            ]
                            ?? 0
                        )
                        >= 80
                    )
                );
            })
            ->count();

        $partialChecks = $allEvaluations
            ->filter(
                fn ($evaluation) =>
                    strtolower(
                        (string) (
                            $evaluation[
                                'classification'
                            ]
                            ?? ''
                        )
                    )
                    === 'partially_correct'
            )
            ->count();

        $latestMisconception = $focusObjectives
            ->pluck('latest_misconception')
            ->filter(
                fn ($value) =>
                    trim((string) $value) !== ''
            )
            ->first();

        $latestEvidence = $focusObjectives
            ->pluck('latest_evidence')
            ->filter(
                fn ($value) =>
                    trim((string) $value) !== ''
            )
            ->first();

        $latestFeedback = $focusObjectives
            ->pluck('latest_feedback')
            ->filter(
                fn ($value) =>
                    trim((string) $value) !== ''
            )
            ->first();

        $headline = match (true) {
            $focusObjectives->count() > 1 =>
                $focusObjectives->count()
                . ' learning objectives still need review',
            $focusObjectives->count() === 1 =>
                '1 learning objective still needs review',
            default =>
                'This topic was moved on without full mastery',
        };

        $teacherFocus = $latestMisconception
            ? 'Address this gap first: '
                . $latestMisconception
            : (
                $latestFeedback
                    ? $latestFeedback
                    : 'Review the unresolved objective, ask the learner to explain it in their own words, then use a short targeted retry.'
            );

        return [
            'headline' => $headline,
            'move_on_reason' =>
                $state->move_on_reason,
            'move_on_at' =>
                optional(
                    $state->move_on_at
                )?->toIso8601String(),
            'session_count' =>
                collect($sessions)->count(),
            'objective_count' =>
                $objectives->count(),
            'needs_review_objective_count' =>
                $needsReviewObjectives->count(),
            'recent_unsuccessful_checks' =>
                $unsuccessfulChecks,
            'recent_partial_checks' =>
                $partialChecks,
            'latest_misconception' =>
                $latestMisconception,
            'what_student_demonstrated' =>
                $latestEvidence,
            'latest_tutor_feedback' =>
                $latestFeedback,
            'teacher_focus' =>
                $teacherFocus,
            'focus_objectives' =>
                $focusObjectives->all(),
        ];
    }

    public function teacherThreads(Request $request)
    {
        $teacher = $this->teacher($request);
        $query = LearningSupportThread::query()
            ->where('teacher_id', $teacher->id)
            ->with([
                'student:id,name,email',
                'learningPeriod:id,title,group_id',
                'topic:id,topic_name',
                'learningObjective:id,topic_id,code,objective,description',
                'messages.sender:id,name,role',
                'checkIns',
            ]);

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->integer('student_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'data' => $query->orderByDesc('last_message_at')->orderByDesc('id')->get(),
        ]);
    }

    public function createThread(Request $request)
    {
        $teacher = $this->teacher($request);
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'learning_period_id' => ['nullable', 'integer', 'exists:learning_periods,id'],
            'topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'learning_objective_id' => [
                'nullable',
                'integer',
                'exists:learning_objectives,id',
            ],
            'type' => ['required', 'in:feedback,check_in,message'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $student = User::findOrFail($data['student_id']);
        abort_unless($student->role === 'student', 422, 'The selected user is not a student.');
        $this->authorizeTeacherStudent($teacher, $student, $data['learning_period_id'] ?? null);

        if (!empty($data['topic_id']) && !empty($data['learning_period_id'])) {
            abort_unless(
                DB::table('learning_period_topics')
                    ->where('learning_period_id', $data['learning_period_id'])
                    ->where('topic_id', $data['topic_id'])
                    ->exists(),
                422,
                'The selected topic is not part of this learning period.'
            );
        }

        if (!empty($data['learning_objective_id'])) {
            abort_unless(
                !empty($data['topic_id']),
                422,
                'A review objective requires a topic.'
            );

            $objective = LearningObjective::query()
                ->where('id', $data['learning_objective_id'])
                ->where('topic_id', $data['topic_id'])
                ->first();

            abort_unless(
                $objective,
                422,
                'The selected learning objective does not belong to this topic.'
            );
        }

        $thread = DB::transaction(function () use ($teacher, $data) {
            $thread = LearningSupportThread::create([
                'student_id' => $data['student_id'],
                'teacher_id' => $teacher->id,
                'learning_period_id' => $data['learning_period_id'] ?? null,
                'topic_id' => $data['topic_id'] ?? null,
                'learning_objective_id' =>
                    $data['learning_objective_id'] ?? null,
                'type' => $data['type'],
                'status' => 'open',
                'subject' => $data['subject'] ?? null,
                'last_message_at' => now(),
            ]);

            $thread->messages()->create([
                'sender_id' => $teacher->id,
                'message' => $data['message'],
            ]);

            if ($data['type'] === 'check_in') {
                $thread->checkIns()->create([
                    'scheduled_at' => $data['scheduled_at'] ?? null,
                    'status' => 'scheduled',
                ]);
            }

            return $thread;
        });

        return response()->json([
            'message' => 'Learning support thread created.',
            'data' => $thread->load(['student:id,name,email', 'learningPeriod:id,title,group_id', 'topic:id,topic_name', 'learningObjective:id,topic_id,code,objective,description', 'messages.sender:id,name,role', 'checkIns']),
        ], 201);
    }

    public function addMessage(Request $request, LearningSupportThread $thread)
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless(
            (int) $thread->student_id === (int) $user->id ||
            (int) $thread->teacher_id === (int) $user->id,
            403
        );

        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        abort_unless($thread->status === 'open', 409, 'This support thread is closed.');

        $message = $thread->messages()->create([
            'sender_id' => $user->id,
            'message' => $data['message'],
        ]);
        $thread->update(['last_message_at' => now()]);

        return response()->json([
            'message' => 'Message sent.',
            'data' => $message->load('sender:id,name,role'),
        ], 201);
    }

    public function scheduleCheckIn(Request $request, LearningSupportThread $thread)
    {
        $teacher = $this->teacher($request);
        abort_unless((int) $thread->teacher_id === (int) $teacher->id, 403);
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'teacher_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $checkIn = $thread->checkIns()->create([
            'scheduled_at' => $data['scheduled_at'],
            'status' => 'scheduled',
            'teacher_notes' => $data['teacher_notes'] ?? null,
        ]);
        $thread->update(['type' => 'check_in']);

        return response()->json(['data' => $checkIn], 201);
    }

    public function updateCheckIn(Request $request, LearningCheckIn $checkIn)
    {
        $teacher = $this->teacher($request);
        $checkIn->loadMissing('thread');
        abort_unless((int) $checkIn->thread->teacher_id === (int) $teacher->id, 403);

        $data = $request->validate([
            'status' => ['required', 'in:scheduled,completed,cancelled'],
            'teacher_notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_required' => ['nullable', 'boolean'],
        ]);

        $checkIn->fill($data);
        $checkIn->completed_at = $data['status'] === 'completed' ? now() : null;
        $checkIn->save();

        return response()->json(['data' => $checkIn]);
    }

    protected function teacher(Request $request): User
    {
        $teacher = $request->user();
        abort_unless($teacher && in_array($teacher->role, ['teacher', 'admin'], true), 403);
        return $teacher;
    }

    protected function authorizeTeacherStudent(User $teacher, User $student, ?int $learningPeriodId): void
    {
        if ($teacher->role === 'admin') {
            return;
        }

        if ($learningPeriodId) {
            $period = LearningPeriod::findOrFail($learningPeriodId);
            abort_unless((int) $period->created_by === (int) $teacher->id, 403);
            abort_unless(
                DB::table('group_students')
                    ->where('group_id', $period->group_id)
                    ->where('student_id', $student->id)
                    ->exists(),
                403
            );
            return;
        }

        abort_unless(
            DB::table('groups')
                ->join('group_students', 'group_students.group_id', '=', 'groups.id')
                ->where('groups.created_by', $teacher->id)
                ->where('group_students.student_id', $student->id)
                ->exists(),
            403
        );
    }
}
