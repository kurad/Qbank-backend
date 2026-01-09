<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use App\Models\StudentAnswer;
use App\Models\StudentAssessment;

class StudentAssessmentController extends Controller
{
    public function startAssessment($assessmentId)
    {
        $studentId = auth()->id();
        $assessment = Assessment::findOrFail($assessmentId);
        $studentAssessment = StudentAssessment::firstOrCreate(
            [
                'student_id' => $studentId,
                'assessment_id' => $assessmentId,
            ],
            [
                'assigned_by' => $assessment->creator_id,
                'assigned_at' => now(),
                'status' => 'in_progress',
            ]
        );
        return response()->json([
            'message' => 'Assessment started successfully',
            'student_assessment' => $studentAssessment,
        ]);
    }
    public function saveAnswer(Request $request)
    {
        $validated = $request->validate([
            'student_assessment_id' => 'required|exists:student_assessments,id',
            'question_id' => 'required|integer',
            'answer' => 'required|string',
        ]);

        $studentAssessment = StudentAssessment::findOrFail($validated['student_assessment_id']);

        // SECURITY: only owner & only in progress
        abort_if(
            $studentAssessment->student_id !== auth()->id() ||
                $studentAssessment->status !== 'in_progress',
            403
        );

        $answer = StudentAnswer::updateOrCreate(
            [
                'student_assessment_id' => $validated['student_assessment_id'],
                'question_id' => $validated['question_id'],
            ],
            [
                'answer' => $validated['answer'],
                'is_correct' => null, // auto-grader sets later
                'points_earned' => 0,
                'submitted_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Answer saved successfully',
            'answer' => $answer,
        ]);
    }
    public function submit($id)
    {
        $assessment = StudentAssessment::with('studentAnswers')->findOrFail($id);

        abort_if(
            $assessment->student_id !== auth()->id() ||
            $assessment->status !== 'in_progress',
            403
        );
        $needsReview = $assessment->studentAnswers()
            ->whereNull('is_correct')
            ->exists();

        $assessment->update([
            'status' => $needsReview ? 'under_review' : 'graded',
            'completed_at' => now(),
            'score' => $assessment->studentAnswers()->sum('points_earned'),
        ]);
        return response()->json([
            'status' => $assessment->status,
        ]);
    }
    public function reviewAnswer(Request $request, $answerId)
    {
        $validated = $request->validate([
            'is_correct' => 'required|boolean',
            'points' => 'required|integer|min:0',
        ]);
        $answer = StudentAnswer::with('studentAssessment.assessment')->findOrFail($answerId);

        // SECURITY: only assessment creator
        abort_if(
            $answer->studentAssessment->assessment->creator_id !== auth()->id(),
            403
        );
        $answer->update([
            'is_correct' => $validated['is_correct'],
            'points_earned' => $validated['points'],
        ]);

        return response()->json([
            'message' => 'Answer reviewed',
            'answer' => $answer,
        ]);
    }
    public function finalize($studentAssessmentId)
    {
        $sa = StudentAssessment::with(['studentAnswers', 'assessment'])->findOrFail($studentAssessmentId);

        abort_if(
            $sa->assessment->creator_id !== auth()->id(),
            403
        );
        $sa->update([
            'score' => $sa->answers->sum('points_earned'),
            'status' => 'graded'
        ]);

        return response()->json([
            'message' => 'Assessment graded successfully',
            'score' => $sa->score,
        ]);
    }
    public function myAssessments()
{
    return StudentAssessment::with('assessment')
        ->where('student_id', auth()->id())
        ->orderBy('created_at', 'desc')
        ->get();
}
public function show($id)
{
    $assessment = StudentAssessment::with(['studentAnswers', 'assessment'])
        ->where('id', $id)
        ->where('student_id', auth()->id())
        ->firstOrFail();

    return response()->json($assessment);
}

}
