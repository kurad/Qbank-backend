<?php
namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'school_name' => 'required|string|max:255',
            'district' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);
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
        ]);
        $school->update($validated);
        return response()->json([
            'school' => $school,
            'school_code' => $school->school_code,
        ]);
    }
}
