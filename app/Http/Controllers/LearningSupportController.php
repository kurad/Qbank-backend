<?php

namespace App\Http\Controllers;

use App\Models\LearningCheckIn;
use App\Models\LearningPeriod;
use App\Models\LearningSupportThread;
use App\Models\StudentTopicLearningState;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningSupportController extends Controller
{
    public function studentThreads(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'student', 403);

        $threads = LearningSupportThread::query()
            ->where('student_id', $user->id)
            ->with([
                'teacher:id,name,email',
                'learningPeriod:id,title',
                'topic:id,topic_name',
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
            ->when($teacher->role !== 'admin', fn ($query) => $query->where('created_by', $teacher->id))
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

        return response()->json(['data' => $rows]);
    }

    public function teacherThreads(Request $request)
    {
        $teacher = $this->teacher($request);
        $query = LearningSupportThread::query()
            ->where('teacher_id', $teacher->id)
            ->with([
                'student:id,name,email',
                'learningPeriod:id,title',
                'topic:id,topic_name',
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

        $thread = DB::transaction(function () use ($teacher, $data) {
            $thread = LearningSupportThread::create([
                'student_id' => $data['student_id'],
                'teacher_id' => $teacher->id,
                'learning_period_id' => $data['learning_period_id'] ?? null,
                'topic_id' => $data['topic_id'] ?? null,
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
            'data' => $thread->load(['student:id,name,email', 'learningPeriod:id,title', 'topic:id,topic_name', 'messages.sender:id,name,role', 'checkIns']),
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
