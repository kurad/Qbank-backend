<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;
use App\Models\StudentAnswer;
use App\Models\StudentAssessment;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\StudentQuestionHistory;

class StudentAnswerController extends Controller
{
    public function storeStudentAnswers(Request $request)
    {
        $request->validate([
            'student_assessment_id' => 'required|exists:student_assessments,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required'
        ]);

        $studentAssessmentId = $request->student_assessment_id;
        $answers = $request->answers;

        $totalScore = 0;
        $maxScore = 0;

        $studentAssessment = StudentAssessment::findOrFail($studentAssessmentId);

        // Authorization check
        if ($studentAssessment->student_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        foreach ($answers as $ans) {
            $question = Question::findOrFail($ans['question_id']);
            $studentAnswerRaw = $ans['answer'];

            // Convert answer for storage
            $answerToStore = is_array($studentAnswerRaw)
                ? json_encode($studentAnswerRaw)
                : (string) $studentAnswerRaw;

            // Decode correct answer
            $correctAnswer = json_decode($question->correct_answer, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $correctAnswer = $question->correct_answer; // plain string
            }

            $isCorrect = false;
            $pointsEarned = 0;

            // Add to max score
            $maxScore += $question->marks;

            switch ($question->question_type) {

                case 'mcq':
                    $options = is_string($question->options)
                        ? json_decode($question->options, true)
                        : $question->options;

                    $studentAnsArr = is_array($studentAnswerRaw)
                        ? $studentAnswerRaw
                        : [$studentAnswerRaw];

                    $studentAnsText = [];

                    foreach ($studentAnsArr as $sa) {
                        if (is_numeric($sa) && isset($options[$sa])) {
                            $studentAnsText[] = $options[$sa];
                        } else {
                            $studentAnsText[] = $sa;
                        }
                    }

                    $correctArr = is_array($correctAnswer)
                        ? $correctAnswer
                        : [$correctAnswer];

                    $isCorrect = (
                        count($studentAnsText) === count($correctArr) &&
                        empty(array_diff($studentAnsText, $correctArr))
                    );

                    if ($isCorrect) {
                        $pointsEarned = $question->marks;
                    }
                    break;

                case 'true_false':
                    $correctArr = array_map('strtolower', (array) $correctAnswer);
                    $isCorrect = in_array(strtolower($studentAnswerRaw), $correctArr);

                    if ($isCorrect) {
                        $pointsEarned = $question->marks;
                    }
                    break;

                case 'short_answer':

                    if ($studentAssessment->assessment->type === 'practice') {

                        // For practice → student self-confirms performance
                        $confidence = $ans['confidence_score'] ?? null;

                        $weights = [
                            0 => 0,
                            1 => 0.5,
                            2 => 0.75,
                            3 => 1
                        ];

                        $weight = $weights[$confidence] ?? 0;
                        $pointsEarned = $question->marks * $weight;

                        $isCorrect = false; // not auto-graded

                        $confidenceScoreToSave = $confidence;
                    } else {

                        // Strict auto-grade for exams/homework
                        $correctText = is_array($correctAnswer)
                            ? $correctAnswer[0]
                            : $correctAnswer;

                        $isCorrect = strtolower(trim($studentAnswerRaw)) === strtolower(trim($correctText));

                        if ($isCorrect) {
                            $pointsEarned = $question->marks;
                        }

                        $confidenceScoreToSave = null;
                    }
                    break;

                default:
                    $confidenceScoreToSave = null;
                    break;
            }

            // Accumulate score
            $totalScore += $pointsEarned;

            // Save student answer
            StudentAnswer::updateOrCreate(
                [
                    'student_assessment_id' => $studentAssessmentId,
                    'question_id' => $question->id
                ],
                [
                    'answer' => $answerToStore,
                    'is_correct' => $isCorrect,
                    'points_earned' => $pointsEarned,
                    'confidence_score' => $confidenceScoreToSave ?? null,
                    'submitted_at' => now()
                ]
            );

            // History for practice improvements
            StudentQuestionHistory::updateOrCreate(
                [
                    'student_id' => Auth::id(),
                    'question_id' => $question->id
                ],
                [
                    'practiced_at' => now()
                ]
            );
        }

        // Update assessment record
        $studentAssessment->score = $totalScore;
        $studentAssessment->max_score = $maxScore;
        $studentAssessment->status = 'completed';
        $studentAssessment->completed_at = now();
        $studentAssessment->save();

        // Mark assessment delivery mode
        $assessment = $studentAssessment->assessment;
        $assessment->delivery_mode = 'online';
        $assessment->save();

        return response()->json([
            'message' => 'Answers submitted successfully',
            'score' => $totalScore,
            'max_score' => $maxScore,
            'status' => 'completed',
            'completed_at' => $studentAssessment->completed_at
        ]);
    }

    public function updateShortAnswerConfidence(Request $request)
    {
        $request->validate([
            'student_assessment_id' => 'required|exists:student_assessments,id',
            'question_id' => 'required|exists:questions,id',
            // Allowed values match practice short_answer conventions (0-3)
            'confidence_score' => 'required|integer|in:0,1,2,3',
        ]);

        $studentAssessment = StudentAssessment::with('assessment')
            ->findOrFail($request->student_assessment_id);

        if ($studentAssessment->student_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($studentAssessment->assessment->type !== 'practice') {
            return response()->json(['error' => 'Only allowed for practice assessments'], 400);
        }

        $question = Question::findOrFail($request->question_id);

        // Only for practice short_answer
        if ($question->question_type !== 'short_answer') {
            return response()->json(['error' => 'Only short answer questions allowed'], 400);
        }
        // Ensure we have sensible max points value to use (use `marks` primarily)
        $questionMax = $question->marks ?? $question->max_points ?? 0;

        $studentAnswer = StudentAnswer::firstOrCreate(
            [
                'student_assessment_id' => $studentAssessment->id,
                'question_id' => $question->id,
            ],
            [
                'student_id' => Auth::id(),
                'answer_text' => null,
                'points_earned' => 0,
                'max_points' => $questionMax,
            ]
        );
        // Prevent re-grading
        if ($studentAnswer->confidence_score !== null) {
            return response()->json(['message' => 'Already graded'], 409);
        }

        // Use same weight mapping as `storeStudentAnswers` for practice short answers
        $weights = [
            0 => 0,
            1 => 0.5,
            2 => 0.75,
            3 => 1,
        ];

        $confidence = (int) $request->confidence_score;
        $weight = $weights[$confidence] ?? 0;

        // Use studentAnswer->max_points if present, otherwise fall back to question max
        $maxForCalculation = $studentAnswer->max_points ?? $questionMax ?? 0;
        $oldPoints = $studentAnswer->points_earned ?? 0;
        $newPoints = round($maxForCalculation * $weight, 2);

        DB::transaction(function () use (
            $studentAnswer,
            $studentAssessment,
            $oldPoints,
            $newPoints,
            $confidence,
            $maxForCalculation
        ) {
            $studentAnswer->update([
                'points_earned' => $newPoints,
                'confidence_score' => $confidence,
                'is_correct' => $newPoints === $maxForCalculation, // still self-graded
            ]);
            // Update total score; max_score unchanged
            $studentAssessment->update([
                'score' => ($studentAssessment->score - $oldPoints) + $newPoints,
            ]);
        });
        // refresh models to ensure we return current DB values
        $studentAnswer->refresh();
        $studentAssessment->refresh();

        return response()->json([
            'message' => 'Confidence updated and score recalculated',
            'question_id' => $question->id,
            'confidence_score' => $confidence,
            'points_earned' => $studentAnswer->points_earned,
            'total_score' => $studentAssessment->score,
            'max_score' => $studentAssessment->max_score,
        ]);
    }
}
