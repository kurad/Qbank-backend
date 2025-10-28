<?php

namespace App\Http\Controllers;

use App\Models\Group;
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
            'group_name' => 'required|string|max:255',
        ]);

        $group = Group::create([
            'group_name' => $validated['group_name'],
            'created_by' => auth()->id(),
        ]);
        return response()->json([$group],201);
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
        $this->authorize('delete', $group);

        $group->delete();

        return response()->json([
            'message' => 'Group deleted successfully',
        ], 204);
    }
    public function addStudents(Request $request, $id)
    {

        $validated = $request->validate([
            'student_ids' => 'required|array',
            'student_ids.*' => 'required|exists:users,id',
        ]);

        $group = Group::findOrFail($id);
        $group->students()->syncWithoutDetaching($validated['student_ids']);

        return response()->json([
            'message' => 'Students added to group successfully',
        ]);
    }
    public function removeStudent($id, $studentId)
    {
        $group = Group::findOrFail($id);
        $group->students()->detach($studentId);

        return response()->json([
            'message' => 'Student removed from group successfully',
        ]);
    }
}
