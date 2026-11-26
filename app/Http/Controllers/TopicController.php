<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\Unit;
use Illuminate\Http\Request;

class TopicController extends Controller
{
    /**
     * List topics visible to the current user.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        abort_unless($user, 401, 'Unauthenticated.');
        abort_if($user->isSystemAdmin(), 403, 'Academic setup is managed by each school.');
        abort_unless($user->school_id, 403, 'Your account is not attached to a school.');

        $query = Topic::with([
            'gradeSubject.subject',
            'gradeSubject.gradeLevel',
            'unit',
        ])->orderBy('created_at', 'desc');

        if ($user->role === 'admin') {
            $query->whereHas('gradeSubject', function ($q) use ($user) {
                $q->where('school_id', $user->school_id);
            });
        } else {
            $query->whereHas('gradeSubject', function ($q) use ($user) {
                $q->where('teacher_id', $user->id)
                    ->where('school_id', $user->school_id);
            });
        }

        if ($request->filled('subject_id')) {
            $query->whereHas('gradeSubject', function ($q) use ($request) {
                $q->where('subject_id', $request->subject_id);
            });
        }

        if ($request->filled('grade_level_id')) {
            $query->whereHas('gradeSubject', function ($q) use ($request) {
                $q->where('grade_level_id', $request->grade_level_id);
            });
        }

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    /**
     * Create or retrieve a teaching area.
     */
    public function createOrGet(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
        ]);

        Subject::where('id', $validated['subject_id'])
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $gradeSubject = GradeSubject::firstOrCreate(
            [
                'grade_level_id' => $validated['grade_level_id'],
                'subject_id' => $validated['subject_id'],
                'school_id' => $user->school_id,
                'teacher_id' => $user->id,
            ]
        );

        return response()->json($gradeSubject->load([
            'subject',
            'gradeLevel',
        ]), 201);
    }

    /**
     * Legacy topic creation endpoint.
     *
     * New code should use:
     * POST /units/{unit}/topics
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'topic_name' => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        Subject::where('id', $validated['subject_id'])
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        $gradeSubject = GradeSubject::firstOrCreate(
            [
                'grade_level_id' => $validated['grade_level_id'],
                'subject_id' => $validated['subject_id'],
                'school_id' => $user->school_id,
            ],
            [
                // School Admins define the academic structure but do not
                // become owners of Teaching Areas. Teacher assignment is
                // handled separately.
                'teacher_id' => $user->role === 'teacher' ? $user->id : null,
            ]
        );

        $topic = Topic::create([
            'grade_subject_id' => $gradeSubject->id,
            'unit_id' => null,
            'topic_name' => $validated['topic_name'],
            'order' => $validated['order'] ?? 0,
            'status' => $validated['status'] ?? 'active',
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Topic created successfully',
            'data' => $topic->load([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ]),
        ], 201);
    }

    /**
     * Update a topic.
     */
    public function update(Request $request, Topic $topic)
    {
        $this->authorize('update', $topic);

        // Archived topics belong to a superseded curriculum version. Without
        // this guard the status field below could reactivate one.
        if ($topic->status === 'archived') {
            return response()->json([
                'message' => 'This topic belongs to an archived curriculum version and cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'topic_name' => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($duplicate = $this->duplicateTopicName(
            $topic->unit_id,
            $topic->grade_subject_id,
            $validated['topic_name'],
            $topic->id
        )) {
            return $duplicate;
        }

        $topic->update([
            'topic_name' => $validated['topic_name'],
            'order' => $validated['order'] ?? $topic->order,
            'status' => $validated['status'] ?? $topic->status,
        ]);

        return response()->json([
            'message' => 'Topic updated successfully',
            'data' => $topic->fresh()->load([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ]),
        ]);
    }

    /**
     * Create a topic inside a unit.
     */
    public function storeForUnit(Request $request, Unit $unit)
    {
        $this->authorize('view', $unit);

        $validated = $request->validate([
            'topic_name' => ['required', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        if ($duplicate = $this->duplicateTopicName(
            $unit->id,
            $unit->grade_subject_id,
            $validated['topic_name']
        )) {
            return $duplicate;
        }

        $nextOrder = ((int) Topic::query()
            ->where('unit_id', $unit->id)
            ->where('status', 'active')
            ->max('order')) + 1;

        $topic = Topic::create([
            'grade_subject_id' => $unit->grade_subject_id,
            'unit_id' => $unit->id,
            'topic_name' => $validated['topic_name'],
            'order' => $validated['order'] ?? $nextOrder,
            'status' => $validated['status'] ?? 'active',
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Topic created successfully',
            'data' => $topic->load([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ]),
        ], 201);
    }

    public function topicsByUnit(Unit $unit)
    {
        $gradeSubject = $unit->gradeSubject;
        $user = auth()->user();

        if (!$gradeSubject) {
            return response()->json([
                'message' => 'Teaching Area not found for this unit.',
            ], 404);
        }

        // User must belong to a school.
        if (!$user->school_id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        // Teaching Area must belong to the user's school.
        if ((int) $gradeSubject->school_id !== (int) $user->school_id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        /*
     * Teaching Area authorization:
     *
     * Admin:
     *   Can access any Teaching Area in their school.
     *
     * Teacher:
     *   Can access only Teaching Areas assigned to them.
     *
     * Do not use created_by here.
     * teacher_id is the permanent Teaching Area ownership.
     */
        $isAdmin = $user->role === 'admin';

        $isTeacherOwner =
            $gradeSubject->teacher_id !== null &&
            (int) $gradeSubject->teacher_id === (int) $user->id;

        if (!$isAdmin && !$isTeacherOwner) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $topics = $unit->topics()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('topic_name', '!=', 'Imported Questions')
                    ->orWhereNotNull('created_by');
            })
            // Same definition as GET /topics/{id}/question-count: top-level
            // questions only (sub-questions belong to their parent question).
            ->withCount(['questions' => fn ($query) => $query->whereNull('parent_question_id')])
            ->with([
                'unit',
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'learningObjectives' => fn ($query) => $query->where('status', '!=', 'archived'),
            ])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Unit topics retrieved successfully',
            'data' => $topics,
        ]);
    }

    /**
     * Topics by subject.
     */
    public function topicsBySubject($subjectId = null)
    {
        $user = auth()->user();

        // GET /topics-by-subject passes the subject as ?subject_id=...; only
        // GET /subjects/{id}/topics supplies it as a route parameter.
        $subjectId ??= request()->input('subject_id');

        if (!$subjectId) {
            return response()->json([
                'message' => 'A subject is required.',
            ], 422);
        }

        $query = Topic::whereHas('gradeSubject', function ($q) use ($subjectId, $user) {
            $q->where('subject_id', $subjectId);

            if ($user->role !== 'admin') {
                $q->where('teacher_id', $user->id)
                    ->where('school_id', $user->school_id);
            }
        });

        return response()->json(
            $query->with([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ])
                ->orderBy('created_at', 'asc')
                ->get()
        );
    }

    /**
     * Topics by grade and subject.
     */
    public function topicsByGradeAndSubject($subjectId, $gradeId)
    {
        $user = auth()->user();

        $query = GradeSubject::where('grade_level_id', $gradeId)
            ->where('subject_id', $subjectId);

        if ($user->role !== 'admin') {
            $query->where('teacher_id', $user->id)
                ->where('school_id', $user->school_id);
        }

        $gradeSubject = $query->first();

        if (!$gradeSubject) {
            return response()->json([]);
        }

        return response()->json(
            Topic::where('grade_subject_id', $gradeSubject->id)
                ->with([
                    'unit',
                    'learningObjectives',
                ])
                ->orderBy('order')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Paginated topics by subject and grade.
     */
    public function topicsBySubjectAndGrade($subjectId, $gradeId)
    {
        $user = auth()->user();
        $perPage = min((int) request()->input('limit', 10), 100);

        $topics = Topic::whereHas('gradeSubject', function ($query) use (
            $subjectId,
            $gradeId,
            $user
        ) {
            $query->where('subject_id', $subjectId)
                ->where('grade_level_id', $gradeId);

            if ($user->role !== 'admin') {
                $query->where('teacher_id', $user->id)
                    ->where('school_id', $user->school_id);
            }
        })
            ->with([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ])
            ->orderBy('order')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json($topics);
    }

    /**
     * Topics by subject and grade.
     */
    public function topicsBySubjectGrade($subjectId, $gradeId)
    {
        $user = auth()->user();

        $topics = Topic::whereHas('gradeSubject', function ($query) use (
            $subjectId,
            $gradeId,
            $user
        ) {
            $query->where('subject_id', $subjectId)
                ->where('grade_level_id', $gradeId);

            if ($user->role !== 'admin') {
                $query->where('teacher_id', $user->id)
                    ->where('school_id', $user->school_id);
            }
        })
            ->with([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ])
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json($topics);
    }

    /**
     * A topic name must be unique (case-insensitive) among the ACTIVE topics
     * of a unit, because approving a curriculum refuses duplicates.
     */
    private function duplicateTopicName(
        $unitId,
        $gradeSubjectId,
        string $name,
        ?int $ignoreTopicId = null
    ) {
        $exists = Topic::query()
            ->where('grade_subject_id', $gradeSubjectId)
            ->where('unit_id', $unitId)
            ->where('status', 'active')
            ->when($ignoreTopicId, fn ($q) => $q->where('id', '!=', $ignoreTopicId))
            ->whereRaw('LOWER(TRIM(topic_name)) = ?', [mb_strtolower(trim($name))])
            ->exists();

        return $exists
            ? response()->json([
                'message' => "A topic named '" . trim($name) . "' already exists in this unit.",
            ], 422)
            : null;
    }

    /**
     * Delete a topic.
     */
    public function destroy(Topic $topic)
    {
        $this->authorize('delete', $topic);

        if ($topic->questions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete topic with associated questions.',
            ], 422);
        }

        if ($topic->learningObjectives()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a topic that has learning objectives.',
            ], 422);
        }

        $topic->delete();

        return response()->json([
            'message' => 'Topic deleted successfully',
        ]);
    }
}
