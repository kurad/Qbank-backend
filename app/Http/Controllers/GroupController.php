<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\StudentAssessment;

class GroupController extends Controller
{
    public function index()
    {
        $query = Group::with('students');
        if (auth()->user()->role !== 'admin') {
            $query->where('created_by', auth()->id());
        }
        $groups = $query->get();
        return response()->json($groups);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('groups')->where(fn($q) => $q->where('created_by', auth()->id())),
            ],
        ]);

        $group = Group::create([
            'group_name' => $validated['group_name'],
            'created_by' => auth()->id(),
            'class_code' => strtoupper(Str::random(8)),
        ]);
        return response()->json($group, 201);
    }
    public function show($id)
    {
        $group = Group::with('students')->findOrFail($id);
        return response()->json($group);
    }
    public function update(Request $request, $id)
    {
        $group = Group::findOrFail($id);
        $this->authorize('update', $group);

        $validated = $request->validate([
            'group_name' => 'sometimes|string|max:255',
        ]);

        $group->update($validated);

        return response()->json($group);
    }
    public function destroy($id)
    {
        $group = Group::findOrFail($id);
        $group->delete();

        return response()->json([
            'message' => 'Group deleted successfully',
        ], 200);
    }
    public function addStudents(Request $request, $id)
    {
        $this->authorize('update', Group::findOrFail($id));

        $validated = $request->validate([
            'student_ids' => 'required|array',
            'student_ids.*' => 'required|exists:users,id',
        ]);

        // Ensure all provided users are students
        $studentIds = collect($validated['student_ids'])
            ->unique()
            ->values();

        $nonStudentCount = \App\Models\User::whereIn('id', $studentIds)
            ->where('role', '!=', 'student')
            ->count();

        if ($nonStudentCount > 0) {
            return response()->json([
                'message' => 'Only student users can be added to a group.',
            ], 422);
        }

        $group = Group::findOrFail($id);
        $group->students()->syncWithoutDetaching($studentIds->all());

        $group->load('students');

        return response()->json($group);
    }
    public function myGroups()
    {
        $user = auth()->user();

        $query = Group::with(['students', 'creator']);

        if ($user->role === 'student') {
            // Groups the student belongs to
            $query->whereHas('students', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        } elseif ($user->role !== 'admin') {
            // Non-admin teachers: groups they created
            $query->where('created_by', $user->id);
        }

        $groups = $query->get();

        return response()->json($groups);
    }
    public function joinClassByCode(Request $request)
    {
        $validated = $request->validate([
            'class_code' => 'required|string|exists:groups,class_code',
        ]);

        // Find the group using the provided class code

        $group = Group::where('class_code', $validated['class_code'])->firstOrFail();

        // Ensure only student can join
        if (auth()->user()->role != 'student') {
            return response()->json(['message' => 'Only students can join the class'], 403);
        }
        // Check if the student is already in the group
        $alreadyJoined = $group->students()->wherePivot('student_id', auth()->id())->exists();
        if ($alreadyJoined) {
            return response()->json(['message' => 'You have already joined this class.'], 409);
        }

        //Add the student to the group

        $group->students()->attach(auth()->id());
        return response()->json([
            'message' => 'Successfully joined the class!',
            'group' => $group,
        ]);
    }

    public function removeStudent($id, $studentId)
    {
        $group = Group::findOrFail($id);
        $this->authorize('update', $group);
        $group->students()->detach($studentId);

        $group->load('students');

        return response()->json($group);
    }
    // GET /groups/{id}/assignments
    public function assignments(Request $request, Group $group)
    {
        $user = $request->user();

        // Students: must belong to the group
        if ($user->role === 'student') {
            abort_if(
                ! $user->groups()->where('groups.id', $group->id)->exists(),
                403,
                'You are not a member of this class'
            );
        } else {
            // Non-students (teachers/admins): only group creators or admins can view
            if ($user->role !== 'admin' && $group->created_by !== $user->id) {
                abort(403, 'Not allowed to view assignments for this group');
            }
        }

        $query = $group->assessments()->with('creator:id,name');

        // If teacher (non-admin), only return assessments created by the authenticated teacher
        if ($user->role !== 'admin' && $user->role !== 'student') {
            $query->where('assessments.creator_id', $user->id);
        }

        // For students include their studentAssessment data
        if ($user->role === 'student') {
            $query->with(['studentAssessments' => fn($q) => $q->where('student_id', $user->id)]);
        }

        $assignments = $query
            ->select(
                'assessments.id',
                'assessments.type',
                'assessments.title',
                'assessments.creator_id',
                'assessments.due_date',
                'assessments.time_limit',
                'assessments.created_at'
            )
            ->orderByDesc('assessments.created_at')
            ->get()
            ->map(function ($assessment) use ($user) {
                $result = [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'type' => $assessment->type,
                    'due_date' => $assessment->due_date,
                    'time_limit' => $assessment->time_limit,
                    'creator' => $assessment->creator,
                ];

                if ($user->role === 'student') {
                    $sa = $assessment->studentAssessments->first();
                    $result['student_assessment'] = $sa ? [
                        'id' => $sa->id,
                        'status' => $sa->status,
                        'score' => $sa->score,
                        'completed_at' => $sa->completed_at,
                    ] : null;
                }

                return $result;
            });

        return response()->json($assignments);
    }

    // GET /groups/{group}/assignments/{assessment}/submissions
    public function assignmentSubmissions(Request $request, Group $group, $assessmentId)
    {
        $user = $request->user();

        // Only admins or the group creator may view submissions
        if ($user->role !== 'admin' && $group->created_by !== $user->id) {
            abort(403, 'Not allowed to view submissions for this group');
        }

        $assessment = \App\Models\Assessment::findOrFail($assessmentId);

        // If teacher (non-admin), ensure they created the assessment
        if ($user->role !== 'admin' && $assessment->creator_id !== $user->id) {
            abort(403, 'You did not create this assessment');
        }

        // Load group students and their studentAssessment (if any) for this assessment
        $students = $group->students()->with(['studentAssessments' => function ($q) use ($assessmentId) {
            $q->where('assessment_id', $assessmentId)->with(['studentAnswers.question']);
        }])->get();

        $result = $students->map(function ($student) {
            $sa = $student->studentAssessments->first();
            return [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name ?? $student->email,
                ],
                'student_assessment' => $sa ? [
                    'id' => $sa->id,
                    'status' => $sa->status,
                    'score' => $sa->score,
                    'max_score' => $sa->max_score,
                    'completed_at' => $sa->completed_at,
                    'answers' => $sa->studentAnswers->map(function ($a) {
                        $q = $a->question;
                        $answerRaw = $a->answer;
                        $decoded = null;
                        if (is_string($answerRaw)) {
                            $tmp = json_decode($answerRaw, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $decoded = $tmp;
                            }
                        } elseif (is_array($answerRaw)) {
                            $decoded = $answerRaw;
                        }

                        $base = [
                            'id' => $a->id,
                            'question_id' => $a->question_id,
                            'question_text' => $q?->question,
                            'question_type' => $q?->question_type,
                            'answer' => $a->answer,
                            'is_correct' => $a->is_correct,
                            'points_earned' => $a->points_earned,
                        ];

                        // Enrich matching-type answers with resolved left/right labels and pairs
                        if ($q && $q->question_type === 'matching') {
                            // Decode canonical correct_answer
                            $canonical = null;
                            if (is_string($q->correct_answer)) {
                                $tmp = json_decode($q->correct_answer, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $canonical = $tmp;
                                }
                            } elseif (is_array($q->correct_answer)) {
                                $canonical = $q->correct_answer;
                            }

                            $left = $canonical['left'] ?? [];
                            $right = $canonical['right'] ?? [];
                            $canonicalPairs = $canonical['pairs'] ?? $canonical['matches'] ?? [];

                            // Prepare a case-insensitive map of right labels -> index
                            $rightMap = [];
                            foreach ($right as $idx => $rlabel) {
                                $key = mb_strtolower(trim(is_string($rlabel) ? $rlabel : (string)$rlabel));
                                $rightMap[$key] = $idx;
                            }

                            $studentPairs = [];
                            $studentRaw = [];

                            if (is_array($decoded)) {
                                if (array_key_exists('pairs', $decoded) || array_key_exists('raw', $decoded)) {
                                    $studentRaw = $decoded['raw'] ?? [];
                                    $studentPairs = $decoded['pairs'] ?? [];
                                } elseif (!empty($decoded) && array_key_exists('left_index', reset($decoded) ?: [])) {
                                    // already pairs
                                    $studentPairs = $decoded;
                                } else {
                                    // array of right labels (raw)
                                    $studentRaw = $decoded;
                                }
                            }

                            // If we only have raw labels, map them to right indices
                            if (empty($studentPairs) && !empty($studentRaw)) {
                                foreach ($studentRaw as $li => $rlabel) {
                                    $key = mb_strtolower(trim(is_string($rlabel) ? $rlabel : (string)$rlabel));
                                    $ri = $rightMap[$key] ?? null;
                                    $studentPairs[] = ['left_index' => (int)$li, 'right_index' => $ri, 'right_raw' => $rlabel];
                                }
                            }

                            // If studentPairs exist but may lack right_raw, add readable texts
                            $displayPairs = [];
                            foreach ($studentPairs as $p) {
                                $li = isset($p['left_index']) ? (int)$p['left_index'] : null;
                                $ri = $p['right_index'] ?? ($p['right'] ?? null);
                                $leftText = $left[$li] ?? null;
                                $rightText = $right[$ri] ?? ($p['right_raw'] ?? null);
                                $displayPairs[] = [
                                    'left_index' => $li,
                                    'left_text' => $leftText,
                                    'right_index' => $ri,
                                    'right_text' => $rightText,
                                ];
                            }

                            $base['matching'] = [
                                'left' => $left,
                                'right' => $right,
                                'pairs' => $displayPairs,
                                'canonical_pairs' => $canonicalPairs,
                            ];
                        }

                        return $base;
                    }),
                ] : null,
            ];
        });

        return response()->json([
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
            ],
            'submissions' => $result,
        ]);
    }

}
