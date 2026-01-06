<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

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
                Rule::unique('groups')->where(fn ($q) => $q->where('created_by', auth()->id())),
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
        if(auth()->user()->role != 'student') {
            return response()->json(['message' => 'Only students can join the class'], 403);
        }
        // Check if the student is already in the group
        $alreadyJoined = $group->students()->wherePivot('student_id', auth()->id())->exists();
        if($alreadyJoined){
            return response()->json(['message' => 'You have already joined this class.'], 409);
        }

        //Add the student to the group

        $group->students()->attach(auth()->id());
        return response()->json([
            'message' => 'Successfully joined the class!',
            'group' =>$group,
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
}
