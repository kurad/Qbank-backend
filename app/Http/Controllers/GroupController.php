<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index()
    {
        $groups = Group::with('students')->get();
        return response()->json($groups);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'student_ids' => 'array',
        ]);

        $group = Group::create([
            'name' => $data['name'],
            'created_by' => auth()->id(),
        ]);

        if (!empty($data['student_ids'])) {
            $group->students()->sync($data['student_ids']);
        }

        return response()->json($group->load('students'));
    }
    public function update(Request $request, Group $group)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'student_ids' => 'array',
        ]);

        $group->update(['name' => $data['name']]);
        $group->students()->sync($data['student_ids'] ?? []);

        return response()->json($group->load('students'));
    }
}
