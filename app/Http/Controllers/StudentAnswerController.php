<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;
use App\Models\StudentAnswer;
use App\Models\StudentAssessment;
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
                        $confidence = $ans['confidence_score'] ?? 0;

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
        'confidence_score' => 'required|integer|in:0,1,3,4',
    ]);

    $studentAssessment = StudentAssessment::with('assessment')
        ->findOrFail($request->student_assessment_id);

    if ($studentAssessment->student_id !== Auth::id()) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    $question = Question::findOrFail($request->question_id);

    // Only for practice short_answer
    if (
        $question->question_type !== 'short_answer' ||
        $studentAssessment->assessment->type !== 'practice'
    ) {
        return response()->json(['error' => 'Not allowed for this question/assessment type'], 400);
    }

    $studentAnswer = StudentAnswer::where('student_assessment_id', $studentAssessment->id)
        ->where('question_id', $question->id)
        ->firstOrFail();

    $confidence = $request->confidence_score;

    $weights = [
        0 => 0,
        1 => 0.5,
        3 => 0.75,
        4 => 1,
    ];

    $weight = $weights[$confidence] ?? 0;

    $oldPoints = $studentAnswer->points_earned ?? 0;
    $newPoints = $question->marks * $weight;

    $studentAnswer->points_earned = $newPoints;
    $studentAnswer->confidence_score = $confidence;
    $studentAnswer->is_correct = false; // still self-graded
    $studentAnswer->save();

    // Update total score; max_score unchanged
    $studentAssessment->score = ($studentAssessment->score - $oldPoints) + $newPoints;
    $studentAssessment->save();

    return response()->json([
        'message' => 'Confidence updated and score recalculated',
        'question_id' => $question->id,
        'confidence_score' => $confidence,
        'points_earned' => $newPoints,
        'total_score' => $studentAssessment->score,
        'max_score' => $studentAssessment->max_score,
    ]);
}
}


