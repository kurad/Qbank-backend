<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentSection;
use App\Models\AssessmentSectionQuestion;
use App\Models\Question;
use App\Models\QuestionUsage;
use Illuminate\Http\Request;

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
        $questionIds = $validated['question_ids'] ?? [];

        // Expand parent question IDs into their sub-questions
        $expandedQuestionIds = [];
        if (!empty($questionIds)) {
            $questions = Question::whereIn('id', $questionIds)->with('subQuestions')->get();
            foreach ($questions as $question) {
                if ($question->subQuestions->count() > 0) {
                    $expandedQuestionIds = array_merge($expandedQuestionIds, $question->subQuestions->pluck('id')->toArray());
                } else {
                    $expandedQuestionIds[] = $question->id;
                }
            }
        }

        // If no question_ids: clear all questions from this section
        if (empty($expandedQuestionIds)) {
            if (!empty($existingSection)) {
                AssessmentSectionQuestion::where('assessment_section_id', $section->id)->delete();
            }
        } else {
            // Upsert each question with ordering using updateOrCreate
            foreach (array_values($expandedQuestionIds) as $order => $qid) {
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
            $toRemove = array_diff(array_keys($existingSection), $expandedQuestionIds);
            if (!empty($toRemove)) {
                AssessmentSectionQuestion::where('assessment_section_id', $section->id)
                    ->whereIn('question_id', $toRemove)
                    ->delete();
            }
        }

        // Recalculate total questions for the assessment
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

        // Reload questions for the response
        $section->load('questions');

        return response()->json([
            'message'  => 'Section questions added/updated successfully',
            'warnings' => $warnings,
            'section'  => $section,
        ]);
    }
}
