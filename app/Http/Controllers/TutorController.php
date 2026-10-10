<?php

namespace App\Http\Controllers;

use App\Models\CourseMaterial;
use App\Models\GradeSubject;
use App\Models\Group;
use App\Models\LearningPeriod;
use App\Models\TutorMessage;
use App\Models\TutorSession;
use App\Models\Unit;
use App\Models\Topic;
use App\Services\GroqAIService;
use App\Services\TutorAIService;
use App\Services\TutorCheckpointService;
use App\Services\TutorContextService;
use App\Services\TutorDifficultyService;
use App\Services\TutorSessionService;
use Illuminate\Http\Request;

class TutorController extends Controller

{

    public function __construct(
        protected TutorContextService $context,
        protected TutorSessionService $sessionsService,
        protected TutorAIService $ai,
        protected TutorCheckpointService $checkpoints,
        protected TutorDifficultyService $difficulty

    ) {}

    public function groups(Request $request)

    {

        return response()->json([

            'data' => $this->context->studentGroups($request->user()),

        ]);
    }
    public function subjects(
        Request $request,
        Group $group
    ) {

        $user = $request->user();
        abort_unless($user, 401, 'Unauthenticated.');
        abort_unless($user->role === 'teacher', 403, 'Only teachers can access class subjects.');
        $subjects = GradeSubject::query()
            ->where('teacher_id', $user->id)
            ->where('school_id', $user->school_id)
            ->with([
                'subject:id,name',
                'gradeLevel:id,grade_name',

            ])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $subjects,]);
    }

    public function currentLearningPeriods(
        Request $request,
        Group $group
    ) {
        return response()->json([
            'data' => $this->context->currentLearningPeriods(
                $request->user(),
                $group
            ),

        ]);
    }

    public function learningPeriods(
        Request $request,
        Group $group
    ) {
        $today = now()->toDateString();

        $periods = $this->context
            ->learningPeriodsForStudent($request->user(), $group, false)
            ->filter(
                fn(LearningPeriod $period) =>
                $period->start_date->toDateString() <= $today
            )
            ->map(function (LearningPeriod $period) use ($today) {
                $period->setAttribute(
                    'learning_state',
                    $period->end_date->toDateString() < $today
                        ? 'previous'
                        : 'current'
                );

                return $period;
            })
            ->values();

        return response()->json([
            'data' => $periods,
        ]);
    }

    public function learningPeriodTopics(

        Request $request,

        Group $group,

        LearningPeriod $learningPeriod

    ) {

        return response()->json([

            'data' =>

            $this->context->learningPeriodTopics(

                $request->user(),

                $group,

                $learningPeriod

            ),

        ]);
    }

    public function units(

        Request $request,

        Group $group,

        GradeSubject $gradeSubject

    ) {

        return response()->json([

            'data' => $this->context->units(

                $request->user(),

                $group,

                $gradeSubject

            ),

        ]);
    }

    public function topics(

        Request $request,

        Group $group,

        GradeSubject $gradeSubject,

        $unit

    ) {

        $unit = Unit::findOrFail($unit);

        return response()->json([

            'data' => $this->context->topics(

                $request->user(),

                $group,

                $gradeSubject,

                $unit

            ),

        ]);
    }

    public function objectives(

        Request $request,

        Group $group,

        GradeSubject $gradeSubject,

        $unit,

        $topic

    ) {

        $unit = Unit::findOrFail($unit);

        $topic = Topic::findOrFail($topic);

        return response()->json([

            'data' => $this->context->objectives(

                $request->user(),

                $group,

                $gradeSubject,

                $unit,

                $topic

            ),

        ]);
    }

    public function createSession(Request $request)

    {

        $data = $request->validate([

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

            'grade_subject_id' => [

                'required',

                'integer',

                'exists:grade_subjects,id',

            ],

            'topic_id' => [

                'required',

                'integer',

                'exists:topics,id',

            ],

        ]);

        $session = $this->sessionsService->create(

            $request->user(),

            $data

        );

        return response()->json([

            'message' => 'Tutor session started successfully.',

            'data' => $session,

        ], 201);
    }

    public function message(

        Request $request,

        TutorSession $tutorSession

    ) {

        $this->authorizeStudentSession(

            $request,

            $tutorSession

        );

        $this->ensureActiveSession($tutorSession);

        $data = $request->validate([

            'message' => [

                'required',

                'string',

                'max:4000',

            ],

        ]);

        $currentObjective =

            $this->sessionsService->currentObjective(

                $tutorSession

            );

        abort_unless($currentObjective, 422, 'There is no active learning objective in this session.');

        $knowledge =

            $this->ai->teachingContext(

                $tutorSession,

                $data['message'],

                6

            );

        $answer =

            $this->ai->respondToStudentMessage(

                $tutorSession,

                $data['message'],

                $knowledge

            );

        $userMessage =

            $tutorSession

            ->messages()

            ->create([

                'role' => 'user',

                'message_type' => 'text',

                'content' => $data['message'],

            ]);

        $assistantMessage =

            $tutorSession

            ->messages()

            ->create([

                'role' => 'assistant',

                'message_type' => 'text',

                'content' => $answer,

            ]);

        $tutorSession->update(['last_activity_at' => now(),]);

        $pendingCheckpoint =

            $this->checkpoints

            ->pendingCheckpoint(

                $tutorSession

            );

        $freshSession =

            $this->sessionsService

            ->freshSession(

                $tutorSession

            );

        return response()->json([

            'data' => [

                'session_id' => $tutorSession->id,

                'answer' => $answer,

                'message' => $this->publicMessage($assistantMessage),

                'student_message' => $this->publicMessage($userMessage),

                'pending_checkpoint' =>

                $pendingCheckpoint

                    ? $this->checkpoints

                    ->publicCheckpoint(

                        $pendingCheckpoint

                    )

                    : null,

                'progress' => [

                    'objective_completed' => false,

                    'session_completed' => false,

                    'current_objective' =>

                    $this->publicObjective(

                        $currentObjective

                    ),

                    ...$this->difficulty->profile(

                        $tutorSession,

                        $currentObjective

                    ),

                ],

                'session' => $freshSession,

            ],

        ]);
    }

    public function introduceCurrentObjective(

        Request $request,

        TutorSession $tutorSession

    ) {

        $this->authorizeStudentSession(
            $request,
            $tutorSession
        );

        $this->ensureActiveSession($tutorSession);

        $currentObjective =
            $this->sessionsService->currentObjective(
                $tutorSession
            );

        abort_unless(
            $currentObjective,
            422,
            'There is no active learning objective to introduce.'
        );

        $answer =
            $this->ai->introduceCurrentObjective(
                $tutorSession
            );

        $assistantMessage =
            $tutorSession
            ->messages()
            ->create([
                'role' => 'assistant',
                'message_type' => 'objective_intro',
                'content' => $answer,
                'metadata' => [
                    'objective_id' => $currentObjective->id,
                ],
            ]);

        $tutorSession->update([
            'last_activity_at' => now(),
        ]);

        $freshSession =
            $this->sessionsService
            ->freshSession(
                $tutorSession
            );

        return response()->json([
            'data' => [
                'session_id' => $tutorSession->id,
                'answer' => $answer,
                'assistant_message' =>
                $this->publicMessage(
                    $assistantMessage
                ),
                'current_objective' =>
                $this->publicObjective(
                    $currentObjective
                ),
                'progress' => [
                    'objective_completed' => false,
                    'session_completed' => false,
                    'current_objective' =>
                    $this->publicObjective(
                        $currentObjective
                    ),
                    ...$this->difficulty->profile(
                        $tutorSession,
                        $currentObjective
                    ),
                ],
                'session' => $freshSession,
            ],
        ]);
    }

    public function continueLearning(

        Request $request,

        TutorSession $tutorSession

    ) {

        $this->authorizeStudentSession(

            $request,

            $tutorSession

        );

        $this->ensureActiveSession($tutorSession);

        $currentObjective =

            $this->sessionsService->currentObjective(

                $tutorSession

            );

        abort_unless(

            $currentObjective,

            422,

            'There is no active learning objective in this session.'

        );

        $difficulty = $this->difficulty->profile(

            $tutorSession,

            $currentObjective

        );

        abort_unless(

            $difficulty['checkpoint_ready'] ?? true,

            422,

            'Spend a little more time working through the explanation with your Tutor before trying another learning check.'

        );

        $checkpointMessage =

            $this->checkpoints->generate(

                $tutorSession

            );

        $tutorSession->update(['last_activity_at' => now(),]);

        return response()->json([

            'data' => [

                'session_id' => $tutorSession->id,

                'checkpoint' =>

                $this->checkpoints

                    ->publicCheckpoint(

                        $checkpointMessage

                    ),

                'current_objective' =>

                $this->publicObjective(

                    $currentObjective

                ),

                'learning_state' => 'checking_understanding',

            ],

        ]);
    }

    public function answerCheckpoint(

        Request $request,

        TutorSession $tutorSession,

        TutorMessage $checkpointMessage

    ) {

        $this->authorizeStudentSession(

            $request,

            $tutorSession

        );

        $this->ensureActiveSession(

            $tutorSession

        );

        abort_unless(

            (int) $checkpointMessage

                ->tutor_session_id === (int) $tutorSession->id,

            404

        );

        abort_unless($checkpointMessage->role === 'assistant' && $checkpointMessage->message_type === 'checkpoint', 404);

        $checkpoint =

            $checkpointMessage

                ->metadata['checkpoint']

            ?? null;

        abort_unless(

            is_array($checkpoint),

            422,

            'Checkpoint information is missing.'

        );

        $type =

            $checkpoint['type']

            ?? null;

        if ($type === 'matching') {

            $data = $request->validate([

                'answer' => [

                    'required',

                    'array',

                    'min:1',

                ],

                'answer.*' => [

                    'required',

                    'string',

                    'max:20',

                ],

            ]);
        } else {

            $responseDepth =

                $checkpoint['response_depth']

                ?? 'short';

            $maxLength = match ($responseDepth) {

                'extended' => 12000,

                'medium' => 8000,

                default => 4000,
            };

            $data = $request->validate([

                'answer' => [

                    'required',

                    'string',

                    'max:' . $maxLength,

                ],

            ]);
        }

        $result =

            $this->checkpoints->answer(

                $tutorSession,

                $checkpointMessage,

                $data['answer'],

                $request->user()->id

            );

        $answeredCheckpoint = $checkpointMessage->fresh();

        $progress = $result['progress'];

        $freshSession =

            $this->sessionsService

            ->freshSession(

                $tutorSession

            );

        $currentObjective =

            $this->sessionsService

            ->currentObjective(

                $tutorSession->fresh()

            );

        /*
         * Keep a knowledge check flowing without another learner click.
         *
         * When the current objective is still unresolved, the feedback has
         * already been created by TutorCheckpointService::answer(). We now
         * prepare the next checkpoint immediately and return it with the same
         * response. This applies after correct, partially-correct, unclear,
         * and incorrect answers.
         *
         * If the objective has just been resolved, we do not create another
         * checkpoint here; the existing objective-transition flow takes over.
         */
        $nextCheckpoint = null;

        if (
            empty($progress['session_completed'])
            && empty($progress['objective_resolved'])
        ) {
            try {
                $nextCheckpointMessage =
                    $this->checkpoints->generate(
                        $tutorSession->fresh(),
                        true
                    );

                $nextCheckpoint =
                    $this->checkpoints->publicCheckpoint(
                        $nextCheckpointMessage
                    );
            } catch (\Throwable $e) {
                /*
                 * The submitted answer and feedback are already safely saved.
                 * Do not fail the whole response if follow-up generation has
                 * a temporary AI/provider problem. The learner can still use
                 * the normal continue/check action as a fallback.
                 */
                report($e);
            }
        }

        return response()->json([

            'data' => [

                'session_id' =>

                $tutorSession->id,

                'checkpoint' =>

                $this->checkpoints

                    ->publicCheckpoint(

                        $answeredCheckpoint

                    ),

                'answer_message' =>

                $this->publicMessage(

                    $result['student_message']

                ),

                'feedback' => $result['feedback_message']->content,

                'feedback_message' =>

                $this->publicMessage(

                    $result['feedback_message']

                ),

                'result' => [

                    'correct' =>

                    (bool) (

                        $result['evaluation']['correct']

                        ?? false

                    ),

                ],

                'progress' => [

                    'objective_completed' =>

                    (bool) (

                        $progress['objective_completed']

                        ?? false

                    ),

                    'objective_resolved' =>

                    (bool) (

                        $progress['objective_resolved']

                        ?? $progress['objective_completed']

                        ?? false

                    ),

                    'objective_status' =>

                    $progress['objective_status']

                        ?? null,

                    'session_completed' =>

                    (bool) (

                        $progress['session_completed']

                        ?? false

                    ),

                    'attempt_number' =>

                    $progress['attempt_number']

                        ?? null,

                    'correct_attempts' =>

                    $progress['correct_attempts']

                        ?? null,

                    'teaching_action' =>

                    $progress['teaching_action']

                        ?? null,

                    'learning_state' =>

                    $progress['learning_state']

                        ?? null,

                    'checkpoint_ready' =>

                    $progress['checkpoint_ready']

                        ?? true,

                    'struggle_level' =>

                    $progress['struggle_level']

                        ?? 0,

                    'consecutive_unsuccessful' =>

                    $progress['consecutive_unsuccessful']

                        ?? 0,

                    'teacher_attention' =>

                    (bool) ($progress['teacher_attention'] ?? false),

                    'teacher_attention_reason' =>

                    $progress['teacher_attention_reason']

                        ?? null,

                    'repeated_misconception' =>

                    $progress['repeated_misconception']

                        ?? null,

                    'support_turns_since_check' =>

                    $progress['support_turns_since_check']

                        ?? 0,

                    'required_support_turns' =>

                    $progress['required_support_turns']

                        ?? 0,

                    'max_persistence_reached' =>

                    (bool) ($progress['max_persistence_reached'] ?? false),

                    'current_objective' =>

                    $currentObjective

                        ? $this->publicObjective(

                            $currentObjective

                        )

                        : null,

                ],

                'next_checkpoint' =>
                    $nextCheckpoint,

                'outstanding_checks' =>

                $this->checkpoints->outstandingCount($tutorSession),

                'session' => $freshSession,

            ],

        ]);
    }

    public function nextObjective(
        Request $request,
        TutorSession $tutorSession
    ) {
        /*
         * Use the same introduction flow as introduceCurrentObjective().
         *
         * The older implementation returned:
         *
         *   { message, session }
         *
         * while the Vue client expects:
         *
         *   { data: { assistant_message, progress, session, ... } }
         *
         * Because of that mismatch, the new objective introduction was never
         * added to the conversation and the UI kept the previous learning
         * support state, making "Check understanding" appear immediately.
         */
        $this->authorizeStudentSession(
            $request,
            $tutorSession
        );

        $this->ensureActiveSession(
            $tutorSession
        );

        $currentObjective =
            $this->sessionsService->currentObjective(
                $tutorSession
            );

        abort_unless(
            $currentObjective,
            422,
            'There is no active learning objective to introduce.'
        );

        /*
         * Generate a proper teaching introduction for the newly activated
         * objective. TutorAIService is explicitly instructed not to create a
         * formal checkpoint here.
         */
        $answer =
            $this->ai->introduceCurrentObjective(
                $tutorSession
            );

        /*
         * Persist the introduction in the Tutor conversation. This is
         * important both for the learner UI and for later AI context.
         */
        $assistantMessage =
            $tutorSession
            ->messages()
            ->create([
                'role' => 'assistant',
                'message_type' => 'objective_intro',
                'content' => $answer,
                'metadata' => [
                    'objective_id' =>
                        $currentObjective->id,
                ],
            ]);

        $tutorSession->update([
            'last_activity_at' => now(),
        ]);

        $freshSession =
            $this->sessionsService->freshSession(
                $tutorSession
            );

        return response()->json([
            'data' => [
                'session_id' =>
                    $tutorSession->id,

                'answer' =>
                    $answer,

                'assistant_message' =>
                    $this->publicMessage(
                        $assistantMessage
                    ),

                'current_objective' =>
                    $this->publicObjective(
                        $currentObjective
                    ),

                /*
                 * Refresh the adaptive state for the NEW objective.
                 * This prevents stale checkpoint_ready / learning_state
                 * values from the completed objective leaking into the UI.
                 */
                'progress' => [
                    'objective_completed' =>
                        false,

                    'objective_resolved' =>
                        false,

                    'session_completed' =>
                        false,

                    'learning_state' =>
                        'teaching',

                    'current_objective' =>
                        $this->publicObjective(
                            $currentObjective
                        ),

                    ...$this->difficulty->profile(
                        $tutorSession,
                        $currentObjective
                    ),
                ],

                'pending_checkpoint' =>
                    null,

                'session' =>
                    $freshSession,
            ],
        ]);
    }

    public function skipCheckpoint(

        Request $request,

        TutorSession $tutorSession,

        TutorMessage $checkpointMessage

    ) {

        $this->authorizeStudentSession($request, $tutorSession);

        $this->ensureActiveSession($tutorSession);

        abort_unless(

            (int) $checkpointMessage->tutor_session_id

                === (int) $tutorSession->id,

            404

        );

        $skipped = $this->checkpoints->skip(

            $tutorSession,

            $checkpointMessage

        );

        $freshSession = $this->sessionsService->freshSession(

            $tutorSession

        );

        return response()->json([

            'data' => [

                'session_id' => $tutorSession->id,

                'checkpoint' =>

                $this->checkpoints->publicCheckpoint($skipped),

                'pending_checkpoint' => null,

                'outstanding_checks' =>

                $this->checkpoints->outstandingCount($tutorSession),

                'session' => $freshSession,

            ],

        ]);
    }

    public function sessions(Request $request)

    {

        $data = $request->validate([

            'status' => [

                'nullable',

                'in:active,completed,abandoned',

            ],

            'group_id' => [

                'nullable',

                'integer',

            ],

            'learning_period_id' => [

                'nullable',

                'integer',

            ],

            'topic_id' => [

                'nullable',

                'integer',

            ],

            'per_page' => [

                'nullable',

                'integer',

                'min:1',

                'max:100',

            ],

        ]);

        $sessions =

            TutorSession::query()

            ->where(

                'student_id',

                $request->user()->id

            )

            ->when(

                $data['status'] ?? null,

                fn($query, $status) =>

                $query->where(

                    'status',

                    $status

                )

            )

            ->when(

                $data['group_id'] ?? null,

                fn($query, $id) =>

                $query->where(

                    'group_id',

                    $id

                )

            )

            ->when(

                $data['learning_period_id'] ?? null,

                fn($query, $id) =>

                $query->where(

                    'learning_period_id',

                    $id

                )

            )

            ->when(

                $data['topic_id'] ?? null,

                fn($query, $id) =>

                $query->where(

                    'topic_id',

                    $id

                )

            )

            ->with([

                'group:id,group_name,class_code',

                'learningPeriod:id,group_id,grade_subject_id,title,start_date,end_date,status',

                'gradeSubject.subject:id,name',

                'unit:id,name',

                'topic:id,topic_name',

            ])

            ->withCount('messages')

            ->latest('last_activity_at')

            ->paginate(

                (int) (

                    $data['per_page']

                    ?? 15

                )

            );

        return response()->json([

            'data' => $sessions,

        ]);
    }

    public function showSession(

        Request $request,

        TutorSession $tutorSession

    ) {

        $this->authorizeStudentSession(

            $request,

            $tutorSession

        );

        $session =

            $tutorSession->load([

                'group:id,group_name,class_code',

                'learningPeriod:id,group_id,grade_subject_id,title,description,start_date,end_date,status',

                'gradeSubject.subject:id,name',

                'gradeSubject.gradeLevel:id,grade_name',

                'unit:id,name',

                'topic:id,topic_name',

                'objectives.learningObjective:id,topic_id,code,objective,description,order,status',

                'messages:id,tutor_session_id,role,message_type,content,metadata,created_at',

            ]);

        $messages = $session->messages

            ->map(function (

                TutorMessage $message

            ) {

                if ($message->role === 'assistant' && $message->message_type === 'checkpoint') {

                    return [

                        'id' => $message->id,

                        'tutor_session_id' => $message->tutor_session_id,

                        'role' => $message->role,

                        'message_type' => 'checkpoint',

                        'content' => $message->content,

                        'checkpoint' => $this->checkpoints->publicCheckpoint($message),

                        'created_at' => $message->created_at,

                    ];
                }

                return $this->publicMessage($message);
            })

            ->values();

        $pendingCheckpoint =

            $session->status === 'active'

            ? $this->checkpoints

            ->pendingCheckpoint(

                $session

            )

            : null;

        /*

         * Remove loaded raw messages before returning the

         * session object.

         */

        $session->unsetRelation(

            'messages'

        );

        $sessionData =

            $session->toArray();

        $sessionData['messages'] =

            $messages;

        $sessionData['pending_checkpoint'] =

            $pendingCheckpoint

            ? $this->checkpoints

            ->publicCheckpoint(

                $pendingCheckpoint

            )

            : null;

        $currentObjective = $this->sessionsService->currentObjective($session);

        $sessionData['learning_support'] = $currentObjective

            ? $this->difficulty->profile($session, $currentObjective)

            : [

                'learning_state' => 'completed',

                'checkpoint_ready' => false,

                'teacher_attention' => false,

                'struggle_level' => 0,

            ];

        return response()->json([

            'data' => $sessionData,

        ]);
    }

    /**

     * ---------------------------------------------------------

     * End Tutor session.

     * ---------------------------------------------------------

     */

    public function endSession(

        Request $request,

        TutorSession $tutorSession

    ) {

        $this->authorizeStudentSession(

            $request,

            $tutorSession

        );

        $data = $request->validate([

            'status' => [

                'nullable',

                'in:completed,abandoned',

            ],

        ]);

        return response()->json([

            'data' =>

            $this->sessionsService->finish(

                $request->user(),

                $tutorSession,

                $data['status']

                    ?? 'abandoned'

            ),

        ]);
    }

    /**

     * ---------------------------------------------------------

     * Legacy Tutor endpoint.

     * ---------------------------------------------------------

     *

     * Retained temporarily for old clients.

     */

    public function ask(

        Request $request,

        GroqAIService $groqAI

    ) {

        $data = $request->validate([

            'course_material_id' => [

                'required',

                'integer',

                'exists:course_materials,id',

            ],

            'session_id' => [

                'nullable',

                'integer',

                'exists:tutor_sessions,id',

            ],

            'message' => [

                'required',

                'string',

                'max:4000',

            ],

            'conversation' => [

                'nullable',

                'array',

                'max:20',

            ],

            'conversation.*.role' => [

                'required',

                'in:user,assistant',

            ],

            'conversation.*.content' => [

                'required',

                'string',

                'max:4000',

            ],

        ]);

        $material =

            CourseMaterial::query()

            ->whereKey(

                $data['course_material_id']

            )

            ->where(

                'status',

                'approved'

            )

            ->where(

                'extraction_status',

                'ready'

            )

            ->whereNotNull(

                'extracted_text'

            )

            ->firstOrFail();

        $session = null;

        if (!empty($data['session_id'])) {

            $session =

                TutorSession::whereKey(

                    $data['session_id']

                )

                ->where(

                    'student_id',

                    $request->user()->id

                )

                ->firstOrFail();
        }

        if (!$session) {

            $session =

                TutorSession::create([

                    'student_id' =>

                    $request->user()->id,

                    'course_material_id' =>

                    $material->id,

                    'title' =>

                    $material->title,

                    'status' =>

                    'active',

                    'started_at' =>

                    now(),

                    'last_activity_at' =>

                    now(),

                ]);
        }

        $messages =

            $session

            ->messages()

            ->latest()

            ->limit(20)

            ->get()

            ->reverse()

            ->values()

            ->map(

                fn(

                    TutorMessage $message

                ) => [

                    'role' =>

                    $message->role,

                    'content' =>

                    $message->content,

                ]

            )

            ->all();

        if (empty($messages)) {

            $messages =

                collect(

                    $data['conversation'] ?? []

                )

                ->map(

                    fn(array $message) => [

                        'role' =>

                        $message['role'],

                        'content' =>

                        $message['content'],

                    ]

                )

                ->all();
        }

        $messages[] = [

            'role' => 'user',

            'content' =>

            $data['message'],

        ];

        $answer =

            $groqAI->askTutor(

                $material->title,

                $material->extracted_text,

                $messages

            );

        $session->messages()->createMany([

            [

                'role' => 'user',

                'message_type' => 'text',

                'content' => $data['message'],

            ],

            [

                'role' => 'assistant',

                'message_type' => 'text',

                'content' => $answer,

            ],

        ]);

        $session->update(['last_activity_at' => now(),]);

        return response()->json([

            'data' => [

                'session_id' => $session->id,

                'answer' => $answer,

                'course_material' => [

                    'id' => $material->id,

                    'title' => $material->title,

                    'subject_id' => $material->subject_id,

                    'topic_id' => $material->topic_id,

                ],

            ],

        ]);
    }

    protected function authorizeStudentSession(

        Request $request,

        TutorSession $session

    ): void {

        abort_unless((int) $session->student_id === (int) $request->user()->id, 403);
    }

    protected function ensureActiveSession(

        TutorSession $session

    ): void {

        abort_if($session->status !== 'active', 422, 'This Tutor session is no longer active.');
    }

    protected function publicMessage(

        TutorMessage $message

    ): array {

        return [

            'id' => $message->id,

            'tutor_session_id' => $message->tutor_session_id,

            'role' => $message->role,

            'message_type' => $message->message_type,

            'content' => $message->content,

            'created_at' => $message->created_at,

        ];
    }

    protected function publicObjective(

        $sessionObjective

    ): ?array {

        if (!$sessionObjective) {

            return null;
        }

        $objective = $sessionObjective->learningObjective;

        return [

            'id' => $sessionObjective->id,

            'learning_objective_id' => $sessionObjective->learning_objective_id,

            'objective_order' => $sessionObjective->objective_order,

            'status' => $sessionObjective->status,

            'objective' => $objective ? [

                'id' => $objective->id,

                'code' => $objective->code,

                'objective' => $objective->objective,

                'description' => $objective->description,

            ] : null,

        ];
    }
}
