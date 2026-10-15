<?php

namespace App\Http\Controllers;

use App\Models\LearnerFormativeAttempt;
use App\Models\LearningPeriod;
use App\Services\LearnerFormativeService;
use Illuminate\Http\Request;

class StudentLearnerFormativeController extends Controller
{
    public function show(
        Request $request,
        LearningPeriod $learningPeriod,
        LearnerFormativeService $service
    ) {
        $student = $this->authorizedStudent($request, $learningPeriod);

        return response()->json([
            'data' => $service->statusForStudent($student, $learningPeriod),
        ]);
    }

    public function prepare(
        Request $request,
        LearningPeriod $learningPeriod,
        LearnerFormativeService $service
    ) {
        $student = $this->authorizedStudent($request, $learningPeriod);
        $service->beginPreparation($student, $learningPeriod);

        return response()->json([
            'data' => $service->statusForStudent($student, $learningPeriod),
        ], 202);
    }

    public function questions(
        Request $request,
        LearnerFormativeAttempt $attempt,
        LearnerFormativeService $service
    ) {
        $student = $request->user();
        abort_unless($student && $student->role === 'student', 403);

        return response()->json(
            $service->questionsForAttempt($student, $attempt)
        );
    }


    public function submit(
        Request $request,
        LearnerFormativeAttempt $attempt
    ) {
        $student = $request->user();
        abort_unless($student && $student->role === 'student', 403);
        abort_unless((int) $attempt->student_id === (int) $student->id, 403);
        abort_unless(in_array($attempt->status, ['ready', 'in_progress'], true), 409, 'This assessment cannot be submitted in its current state.');
        abort_unless($attempt->assessment_id, 409, 'Assessment preparation is incomplete.');

        $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'exists:questions,id'],
            'answers.*.answer' => ['nullable'],
            'answers.*.confidence_score' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);

        $allowedQuestionIds = $attempt->questions()->pluck('question_id');
        $submittedQuestionIds = collect($request->input('answers', []))
            ->pluck('question_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        abort_if(
            $submittedQuestionIds->diff($allowedQuestionIds)->isNotEmpty(),
            422,
            'The submitted answers contain questions outside this learner assessment.'
        );

        abort_if(
            $allowedQuestionIds->diff($submittedQuestionIds)->isNotEmpty(),
            422,
            'Please submit all questions in your learner assessment.'
        );

        $request->merge([
            'assessment_id' => $attempt->assessment_id,
        ]);

        $response = app(StudentAnswerController::class)
            ->storeStudentAnswers($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $attempt->update([
                'student_assessment_id' => $payload['student_assessment_id'] ?? $attempt->student_assessment_id,
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'message' => 'Assessment completed.',
                'completed_at' => now(),
            ]);
        }

        return $response;
    }

    protected function authorizedStudent(Request $request, LearningPeriod $learningPeriod)
    {
        $student = $request->user();

        abort_unless($student && $student->role === 'student', 403);
        abort_unless(
            $student->groups()->where('groups.id', $learningPeriod->group_id)->exists(),
            403,
            'This learning period is outside your classes.'
        );

        return $student;
    }
}
