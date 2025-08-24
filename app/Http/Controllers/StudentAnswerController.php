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

            // Check if the student is authorized to submit answers for this assessment
            if ($studentAssessment->student_id!== Auth::id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            foreach ($answers as $ans) {
                $question = Question::find($ans['question_id']);
                $studentAnswerRaw = $ans['answer'];
                $isCorrect = false;
                $pointsEarned = 0;

                // Normalize student answer for storage and comparison
                $answerToStore = is_array($studentAnswerRaw)
                    ? json_encode($studentAnswerRaw)
                    : (string)$studentAnswerRaw;

                // Decode correct answer if it's JSON, else use as is
                $correctAnswer = $question->correct_answer;
                $decodedCorrect = json_decode($correctAnswer, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $correctAnswer = $decodedCorrect;
                }

                // Handle answer checking based on question type
                switch ($question->question_type) {
                    case 'mcq':
                        // Map student answer index to option text if needed
                        $options = is_string($question->options) ? json_decode($question->options, true) : $question->options;
                        $studentAnsArr = is_array($studentAnswerRaw) ? $studentAnswerRaw : [$studentAnswerRaw];
                        $studentAnsTextArr = [];
                        foreach ($studentAnsArr as $ans) {
                            if (is_numeric($ans) && isset($options[$ans])) {
                                $studentAnsTextArr[] = $options[$ans];
                            } else {
                                $studentAnsTextArr[] = $ans;
                            }
                        }
                        $correctAnsArr = is_array($correctAnswer) ? $correctAnswer : [$correctAnswer];
                        // Check if all student answers match the correct answers (order-insensitive)
                        $isCorrect = count($studentAnsTextArr) === count($correctAnsArr) && empty(array_diff($studentAnsTextArr, $correctAnsArr));
                        break;
                    case 'true_false':
                        $isCorrect = in_array(strtolower($studentAnswerRaw), array_map('strtolower', (array)$correctAnswer));
                        break;
                    case 'short_answer':
                        $isCorrect = strtolower(trim($studentAnswerRaw)) === strtolower(trim(is_array($correctAnswer) ? $correctAnswer[0] : $correctAnswer));
                        break;
                }

                if ($isCorrect) {
                    $pointsEarned = $question->marks;
                    $totalScore += $pointsEarned;
                }
                $maxScore += $question->marks;

                StudentAnswer::updateOrCreate(
                    [
                        'student_assessment_id' => $studentAssessmentId,
                        'question_id' => $question->id
                    ],
                    [
                        'answer' => $answerToStore,
                        'is_correct' => $isCorrect,
                        'points_earned' => $pointsEarned,
                        'submitted_at' => now()
                    ]
                );
                // Update student question history
                StudentQuestionHistory::firstOrCreate(
                    [
                        'student_id' => Auth::id(),
                        'question_id' => $question->id
                    ],
                    [
                        'practiced_at' => now(),                ]
                );
            }

            // Update score and status in student_assessments table
            $studentAssessment->score = $totalScore;
            $studentAssessment->max_score = $maxScore;
            $studentAssessment->status ='completed';
            $studentAssessment->completed_at = now();
            $studentAssessment->save();

            // Mark the assessment as completed
            $assessment = $studentAssessment->assessment;
            //$assessment->completed = 'Completed';
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
}
