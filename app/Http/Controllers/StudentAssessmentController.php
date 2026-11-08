<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use App\Models\StudentAnswer;
use App\Models\StudentAssessment;
use App\Models\LearnerFormativeAttempt;
use App\Services\LearningPeriodFormativeService;

class StudentAssessmentController extends Controller
{
    public function startAssessment(
        $assessmentId,
        LearningPeriodFormativeService $formativeService
    )
    {
        $studentId = auth()->id();
        $assessment = Assessment::findOrFail($assessmentId);

        $formativeService->assertStudentReadyForAssessment(
            auth()->user(),
            $assessment
        );
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
    public function assessmentHub(Request $request)
    {
        $student = $request->user();

        abort_unless(
            $student && $student->role === 'student',
            403
        );

        $items = StudentAssessment::query()
            ->where('student_id', $student->id)
            ->whereHas('assessment', function ($query) {
                $query->where('type', '!=', 'practice');
            })
            ->with([
                'assessment:id,title,type,creator_id,grade_subject_id,created_at',
                'assessment.creator:id,name',
                'assessment.gradeSubject:id,subject_id,grade_level_id',
                'assessment.gradeSubject.subject:id,name',
                'assessment.gradeSubject.gradeLevel:id,grade_name',
                'assessment.groups:id,group_name,class_code',
            ])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();

        $formativeAttempts = LearnerFormativeAttempt::query()
            ->where('student_id', $student->id)
            ->whereIn(
                'student_assessment_id',
                $items->pluck('id')->filter()
            )
            ->with([
                'learningPeriod:id,title,group_id,grade_subject_id,start_date,end_date,status',
                'learningPeriod.group:id,group_name,class_code',
            ])
            ->orderByDesc('id')
            ->get()
            ->keyBy('student_assessment_id');

        $data = $items->map(function ($item) use ($formativeAttempts) {
            $assessment = $item->assessment;
            $attempt = $formativeAttempts->get($item->id);

            return [
                'student_assessment_id' => $item->id,
                'assessment_id' => $item->assessment_id,
                'title' => $assessment?->title ?? 'Assessment',
                'type' => $assessment?->type ?? 'assessment',
                'status' => $item->status ?? 'pending',
                'assigned_at' => optional($item->assigned_at)?->toIso8601String(),
                'completed_at' => optional($item->completed_at)?->toIso8601String(),
                'score' => $item->score,
                'max_score' => $item->max_score,

                'teacher' => $assessment?->creator?->name,

                'subject' => $assessment?->gradeSubject?->subject?->name,
                'grade' => $assessment?->gradeSubject?->gradeLevel?->grade_name,

                'groups' => $assessment?->groups
                    ?->map(fn ($group) => [
                        'id' => $group->id,
                        'name' => $group->group_name,
                        'class_code' => $group->class_code,
                    ])
                    ->values()
                    ->all() ?? [],

                'formative_attempt' => $attempt
                    ? [
                        'id' => $attempt->id,
                        'status' => $attempt->status,
                        'progress' => $attempt->progress,
                        'stage' => $attempt->stage,
                        'message' => $attempt->message,
                        'question_count' => $attempt->question_count,
                        'error_message' => $attempt->error_message,
                        'learning_period_id' => $attempt->learning_period_id,
                        'learning_period' => $attempt->learningPeriod?->title,
                        'group_id' => $attempt->learningPeriod?->group_id,
                        'group_name' => $attempt->learningPeriod?->group?->group_name,
                    ]
                    : null,
            ];
        });

        return response()->json([
            'data' => $data,
            'summary' => [
                'total' => $data->count(),
                'formative' => $data
                    ->where('type', 'formative')
                    ->count(),
                'assigned' => $data
                    ->where('type', '!=', 'formative')
                    ->count(),
                'pending' => $data
                    ->filter(function ($item) {
                        return !in_array(
                            $item['status'],
                            ['completed', 'graded', 'under_review'],
                            true
                        );
                    })
                    ->count(),
                'completed' => $data
                    ->filter(function ($item) {
                        return in_array(
                            $item['status'],
                            ['completed', 'graded', 'under_review'],
                            true
                        );
                    })
                    ->count(),
            ],
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
