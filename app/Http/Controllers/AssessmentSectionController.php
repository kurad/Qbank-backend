<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Assessment;
use Illuminate\Http\Request;
use App\Models\QuestionUsage;
use App\Models\AssessmentSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\AssessmentSectionQuestion;

class AssessmentSectionController extends Controller
{
    // List all sections (with questions) for a given assessment
    public function index($assessmentId)
    {
        $assessment = Assessment::with('sections.questions')->findOrFail($assessmentId);

        return response()->json([
            'assessment_id' => $assessment->id,
            'sections' => $assessment->sections,
        ]);
    }

    // Create a new section for an assessment
    public function store(Request $request, $assessmentId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'instruction' => 'nullable|string',
            'ordering' => 'nullable|integer|min:1',
        ]);

        $assessment = Assessment::findOrFail($assessmentId);

        $nextOrder = $validated['ordering']
            ?? (int) ($assessment->sections()->max('ordering') ?? 0) + 1;

        $section = AssessmentSection::create([
            'assessment_id' => $assessment->id,
            'title' => $validated['title'],
            'instruction' => $validated['instruction'] ?? null,
            'ordering' => $nextOrder,
        ]);

        return response()->json($section, 201);
    }

    // Update an existing section
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'instruction' => 'nullable|string',
            'ordering' => 'nullable|integer|min:1',
        ]);

        $section = AssessmentSection::findOrFail($id);
        $assessment = $section->assessment ?? Assessment::find($section->assessment_id);

        if (isset($validated['ordering']) && $assessment) {
            $section->ordering = $validated['ordering'];
        }

        if (isset($validated['title'])) {
            $section->title = $validated['title'];
        }

        if (array_key_exists('instruction', $validated)) {
            $section->instruction = $validated['instruction'];
        }

        $section->save();

        return response()->json($section);
    }

    public function destroy($id)
    {
        $section    = AssessmentSection::findOrFail($id);
        $assessment = $section->assessment ?? Assessment::find($section->assessment_id);

        $section->delete();

        if ($assessment) {
            $totalQuestions = AssessmentSectionQuestion::whereIn(
                'assessment_section_id',
                function ($q) use ($assessment) {
                    $q->select('id')
                        ->from('assessment_sections')
                        ->where('assessment_id', $assessment->id);
                }
            )->count();

            $assessment->question_count = $totalQuestions;
            $assessment->save();
        }

        return response()->json([
            'message' => 'Section deleted successfully',
        ]);
    }

    public function addSectionQuestions(Request $request, $id)
    {
        $validated = $request->validate([
            'question_ids'   => 'nullable|array|min:0',
            'question_ids.*' => 'exists:questions,id',
        ]);

        $section     = AssessmentSection::with('questions')->findOrFail($id);
        $assessment  = $section->assessment ?? Assessment::find($section->assessment_id);

        $now    = now();
        $userId = auth()->id();

        // current questions for this section: [question_id => pivot_id]
        $existingSection = $section->questions->pluck('pivot.id', 'id')->toArray();

        $warnings    = [];
        $questionIds = array_values(array_unique($validated['question_ids'] ?? []));

        // If empty => clear section
        if (empty($questionIds)) {
            AssessmentSectionQuestion::where('assessment_section_id', $section->id)->delete();
        } else {
            foreach ($questionIds as $order => $qid) {
                $usageCount = QuestionUsage::where('question_id', $qid)
                    ->whereHas('assessment', function ($q) use ($userId) {
                        $q->where('creator_id', $userId);
                    })
                    ->count();

                if ($usageCount >= 3) {
                    $warnings[] = "Question ID {$qid} has been used in 3 or more assessments created by you.";
                }

                AssessmentSectionQuestion::updateOrCreate(
                    [
                        'assessment_section_id' => $section->id,
                        'question_id'           => $qid,
                    ],
                    [
                        'ordering'   => $order + 1,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            // Remove questions that are no longer in the list
            $toRemove = array_diff(array_keys($existingSection), $questionIds);
            if (!empty($toRemove)) {
                AssessmentSectionQuestion::where('assessment_section_id', $section->id)
                    ->whereIn('question_id', $toRemove)
                    ->delete();
            }
        }

        // Update assessment question_count
        if ($assessment) {
            $totalQuestions = AssessmentSectionQuestion::whereIn(
                'assessment_section_id',
                function ($q) use ($assessment) {
                    $q->select('id')
                        ->from('assessment_sections')
                        ->where('assessment_id', $assessment->id);
                }
            )->count();

            $assessment->question_count = $totalQuestions;
            $assessment->save();
        }

        $section->load('questions');

        return response()->json([
            'message'  => 'Section questions added/updated successfully',
            'warnings' => $warnings,
            'section'  => $section,
        ]);
    }

    public function insertQuestionsIntoSection(Request $request, $sectionId)
    {
        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
            'after_question_id' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $section = AssessmentSection::findOrFail($sectionId);

        // Optional security: ensure owner
        $assessment = Assessment::findOrFail($section->assessment_id);
        if ($assessment->creator_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::transaction(function () use ($section, $data) {
            // Remove duplicates in incoming list
            $incoming = array_values(array_unique($data['question_ids']));

            // Filter out already-attached questions (avoid unique constraint crash)
            $already = DB::table('assessment_section_questions')
                ->where('assessment_section_id', $section->id)
                ->whereIn('question_id', $incoming)
                ->pluck('question_id')
                ->all();

            $questionIds = array_values(array_diff($incoming, $already));
            if (empty($questionIds)) return;

            // Determine insertion position (1-based)
            $insertPos = null;

            if (!empty($data['position'])) {
                $insertPos = (int) $data['position'];
            } elseif (!empty($data['after_question_id'])) {
                $afterOrdering = DB::table('assessment_section_questions')
                    ->where('assessment_section_id', $section->id)
                    ->where('question_id', $data['after_question_id'])
                    ->value('ordering');

                // If "after" question isn't found, append
                $insertPos = $afterOrdering ? ((int) $afterOrdering + 1) : null;
            }

            // If no position resolved -> append to end
            if (!$insertPos) {
                $max = DB::table('assessment_section_questions')
                    ->where('assessment_section_id', $section->id)
                    ->max('ordering');
                $insertPos = ($max ?: 0) + 1;
            }

            $countToInsert = count($questionIds);

            // Shift existing questions down to make room:
            // All rows with ordering >= insertPos get ordering += countToInsert
            DB::table('assessment_section_questions')
                ->where('assessment_section_id', $section->id)
                ->where('ordering', '>=', $insertPos)
                ->increment('ordering', $countToInsert);

            // Insert new questions into the gap
            $now = now();
            $rows = [];
            foreach ($questionIds as $i => $qid) {
                $rows[] = [
                    'assessment_section_id' => $section->id,
                    'question_id' => $qid,
                    'ordering' => $insertPos + $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('assessment_section_questions')->insert($rows);
        });
        $this->normalizeSectionOrdering($section->id);

        return response()->json(['message' => 'Inserted']);
    }

    private function normalizeSectionOrdering(int $sectionId): void
    {
        $ids = DB::table('assessment_section_questions')
            ->where('assessment_section_id', $sectionId)
            ->orderBy('ordering')
            ->pluck('question_id')
            ->all();

        foreach ($ids as $i => $qid) {
            DB::table('assessment_section_questions')
                ->where('assessment_section_id', $sectionId)
                ->where('question_id', $qid)
                ->update(['ordering' => $i + 1, 'updated_at' => now()]);
        }
    }

    public function reorderSectionQuestions(Request $request, $sectionId)
    {
        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
        ]);

        $section = AssessmentSection::findOrFail($sectionId);
        $assessment = Assessment::findOrFail($section->assessment_id);

        if ($assessment->creator_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::transaction(function () use ($sectionId, $data) {
            // Keep only ids that are actually in this section (avoid corrupting)
            $existing = DB::table('assessment_section_questions')
                ->where('assessment_section_id', $sectionId)
                ->pluck('question_id')
                ->all();

            $existingSet = array_flip($existing);
            $ordered = array_values(array_filter($data['question_ids'], fn($id) => isset($existingSet[$id])));

            // Normalize 1..N
            $now = now();
            foreach ($ordered as $i => $qid) {
                DB::table('assessment_section_questions')
                    ->where('assessment_section_id', $sectionId)
                    ->where('question_id', $qid)
                    ->update(['ordering' => $i + 1, 'updated_at' => $now]);
            }
        });

        return response()->json(['message' => 'Reordered']);
    }
}
