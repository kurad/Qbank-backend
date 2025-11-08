<?php
namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    public function index()
    {
        $schools = School::paginate(10);
        return response()->json($schools);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_name' => 'required|string|max:255',
            'district' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('school_logos', 'public');
            $validated['logo_path'] = $path;
        }

        $school = School::create($validated);
        return response()->json([
            'school' => $school,
            'school_code' => $school->school_code,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $school = School::findOrFail($id);
        $validated = $request->validate([
            'school_name' => 'sometimes|required|string|max:255',
            'district' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'status' => 'nullable|in:active,inactive',
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('school_logos', 'public');
            $validated['logo_path'] = $path;
        }

        $school->update($validated);
        return response()->json([
            'school' => $school,
            'school_code' => $school->school_code,
        ]);
    }
}
