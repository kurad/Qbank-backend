<?php
namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    private function requireSystemAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin(),
            403,
            'Only a System Admin can manage RevisionHub clients.'
        );
    }

    public function index(Request $request)
    {
        $this->requireSystemAdmin($request);
        $schools = School::paginate(10);
        return response()->json($schools);
    }
    public function store(Request $request)
    {
        $this->requireSystemAdmin($request);
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
        $this->requireSystemAdmin($request);
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
