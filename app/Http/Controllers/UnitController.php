<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    /**
     * List all units belonging to a grade subject.
     */
    public function index(Request $request, GradeSubject $gradeSubject)
    {
        $this->authorize('view', $gradeSubject);

        $units = $gradeSubject->units()
            ->withCount('topics')
            ->when(
                $request->has('status'),
                fn($query) => $query->where('status', $request->status)
            )
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Units retrieved successfully',
            'data' => $units,
        ]);
    }

    /**
     * Create a new unit.
     */
    public function store(Request $request, GradeSubject $gradeSubject)
    {
        $this->authorize('view', $gradeSubject);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $unit = DB::transaction(function () use ($validated, $gradeSubject, $request) {
            return Unit::create([
                'grade_subject_id' => $gradeSubject->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'order' => $validated['order'] ?? 0,
                'status' => $validated['status'] ?? 'active',
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Unit created successfully',
            'data' => $unit->load([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
            ]),
        ], 201);
    }

    /**
     * Show a single unit.
     */
    public function show(Unit $unit)
    {
        $this->authorize('view', $unit);

        $unit->load([
            'gradeSubject.subject',
            'gradeSubject.gradeLevel',
            'topics',
        ]);

        return response()->json([
            'message' => 'Unit retrieved successfully',
            'data' => $unit,
        ]);
    }

    /**
     * Update a unit.
     */
    public function update(Request $request, Unit $unit)
    {
        $this->authorize('update', $unit);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $unit->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'] ?? $unit->order,
            'status' => $validated['status'] ?? $unit->status,
        ]);

        return response()->json([
            'message' => 'Unit updated successfully',
            'data' => $unit->fresh()->load([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'topics',
            ]),
        ]);
    }

    /**
     * Delete a unit.
     */
    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);

        if ($unit->topics()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a unit that contains topics. Move or delete its topics first.',
            ], 422);
        }

        $unit->delete();

        return response()->json([
            'message' => 'Unit deleted successfully',
        ]);
    }

}
