<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Assessment;
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
            'assessment_id' => 'required|exists:assessments,id',
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required'
        ]);

        $studentId = auth()->id();
        $assessmentId = $request->assessment_id;

        // 1️⃣ Load assessment with groups & students
        $assessment = Assessment::with('groups.students')->findOrFail($assessmentId);
        $isPractice = $assessment->type === 'practice';

        // 2️⃣ Authorization
        if (! $isPractice) {

            $allowed = $assessment->groups->contains(function ($group) use ($studentId) {
                return $group->students->contains('id', $studentId);
            });

            if (! $allowed) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }
        // 3️⃣ Create or fetch StudentAssessment
        $studentAssessment = StudentAssessment::firstOrCreate(
            [
                'student_id' => $studentId,
                'assessment_id' => $assessmentId,
            ],
            [
                // IMPORTANT: assigned_by must be BIGINT (user id)
                'assigned_by' => $isPractice
                    ? $studentId
                    : $assessment->creator_id, // teacher who created it

                'assigned_at' => now(),
                'status' => 'in_progress',
            ]
        );

        // 4️⃣ Prevent resubmission for teacher-assigned assessments
        if (! $isPractice && $studentAssessment->status === 'completed') {
            return response()->json(['error' => 'Assessment already submitted'], 409);
        }

        $totalScore = 0;
        $maxScore = 0;

        // 5️⃣ Process answers
        foreach ($request->answers as $ans) {

            $question = Question::findOrFail($ans['question_id']);
            $studentAnswerRaw = $ans['answer'];

            $answerToStore = is_array($studentAnswerRaw)
                ? json_encode($studentAnswerRaw)
                : (string) $studentAnswerRaw;

            if (is_string($question->correct_answer)) {
                $decoded = json_decode($question->correct_answer, true);
                $correctAnswer = json_last_error() === JSON_ERROR_NONE
                    ? $decoded
                    : $question->correct_answer;
            } else {
                $correctAnswer = $question->correct_answer;
            }

            $isCorrect = false;
            $pointsEarned = 0;
            $confidenceScoreToSave = null;

            $maxScore += $question->marks;

            switch ($question->question_type) {

                case 'mcq':
                    $options = is_string($question->options)
                        ? json_decode($question->options, true)
                        : $question->options;

                    $studentAnsArr = is_array($studentAnswerRaw)
                        ? $studentAnswerRaw
                        : [$studentAnswerRaw];

                    // Convert any numeric index answers into option text
                    $studentAnsText = array_map(function ($sa) use ($options) {
                        $text = is_numeric($sa) && isset($options[$sa]) ? $options[$sa] : $sa;
                        return is_string($text) ? trim($text, " \t\n\r\0\x0B\"'") : (string) $text;
                    }, $studentAnsArr);

                    // Ensure correct answer(s) are an array and map indices to option text if needed
                    $correctArrRaw = is_array($correctAnswer) ? $correctAnswer : [$correctAnswer];
                    $correctArr = array_map(function ($ca) use ($options) {
                        if (is_numeric($ca) && isset($options[$ca])) {
                            $text = $options[$ca];
                        } else {
                            $text = is_string($ca) ? $ca : (string) $ca;
                        }
                        return trim($text, " \t\n\r\0\x0B\"'");
                    }, $correctArrRaw);

                    // Normalize for case-insensitive comparison
                    $normStudent = array_map(fn($v) => mb_strtolower($v), $studentAnsText);
                    $normCorrect = array_map(fn($v) => mb_strtolower($v), $correctArr);

                    sort($normStudent);
                    sort($normCorrect);

                    $isCorrect = $normStudent === $normCorrect;

                    if ($isCorrect) {
                        $pointsEarned = $question->marks;
                    }
                    break;

                case 'matching':
                    // Student may send either:
                    // - an array of right-side labels (`["Choose columns", ...]`) or
                    // - an object like {pairs:[{left_index,right_index},...], raw:[...labels...]}
                    $studentPairs = [];
                    $studentRaw = [];
                    $incomingPairs = [];

                    if (is_array($studentAnswerRaw)) {
                        // associative with 'raw' or 'pairs' keys
                        if (array_key_exists('raw', $studentAnswerRaw) || array_key_exists('pairs', $studentAnswerRaw)) {
                            $studentRaw = $studentAnswerRaw['raw'] ?? [];
                            $incomingPairs = $studentAnswerRaw['pairs'] ?? [];
                        } else {
                            // plain array: could be labels or array of pair objects
                            $first = reset($studentAnswerRaw);
                            if (is_array($first) && array_key_exists('left_index', $first) && array_key_exists('right_index', $first)) {
                                $incomingPairs = $studentAnswerRaw;
                                $studentRaw = array_map(fn($p) => $p['right_index'] ?? null, $incomingPairs);
                            } else {
                                $studentRaw = $studentAnswerRaw;
                            }
                        }
                    } else {
                        $studentRaw = [$studentAnswerRaw];
                    }

                    // Ensure correct structure: left/right arrays and pairs from canonical answer
                    $correctPairs = [];
                    $correctLeft = $correctRight = [];
                    if (is_array($correctAnswer)) {
                        $correctLeft = $correctAnswer['left'] ?? [];
                        $correctRight = $correctAnswer['right'] ?? [];
                        $correctPairs = $correctAnswer['pairs'] ?? $correctAnswer['matches'] ?? [];
                    } 
                    
                    // Try separate field fallback for stored pairs
                    if (empty($correctPairs) && isset($question->correct_pairs)) {
                        $decodedPairs = is_string($question->correct_pairs)
                            ? json_decode($question->correct_pairs, true)
                            : $question->correct_pairs;
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $correctPairs = $decodedPairs;
                        }
                    }

                    // If the question's `correct_answer` itself is an indexed array of pair objects
                    // (e.g. stored as "[{...},{...}]"), accept that as canonical pairs.
                    if (empty($correctPairs) && is_array($correctAnswer)) {
                        $firstElem = reset($correctAnswer);
                        if (is_array($firstElem) && (array_key_exists('left_index', $firstElem) || array_key_exists('left', $firstElem))) {
                            // treat correctAnswer as the pairs array
                            $correctPairs = $correctAnswer;
                        }
                    }

                    // Build normalized right->index map for canonical right array
                    $rightIndexMap = [];
                    foreach ($correctRight as $idx => $r) {
                        $key = mb_strtolower(trim(is_string($r) ? trim($r, " \t\n\r\0\x0B\"'") : (string)$r));
                        $rightIndexMap[$key] = $idx;
                    }

                    // If incoming pairs already provided, normalize them first (may have null right_index)
                    if (!empty($incomingPairs)) {
                        foreach ($incomingPairs as $p) {
                            $li = (int)($p['left_index'] ?? 0);
                            $ri = $p['right_index'] ?? null;
                            // if right_index is null but raw label exists in studentRaw, map it
                            if ($ri === null && isset($studentRaw[$li])) {
                                $label = $studentRaw[$li];
                                $key = mb_strtolower(trim(is_string($label) ? trim($label, " \t\n\r\0\x0B\"'") : (string)$label));
                                $ri = $rightIndexMap[$key] ?? null;
                            }
                            $studentPairs[] = ['left_index' => $li, 'right_index' => $ri];
                        }
                    } else {
                        // Build studentPairs from raw labels (assume order aligns with left indices)
                        foreach ($studentRaw as $leftIndex => $studentRight) {
                            $key = mb_strtolower(trim(is_string($studentRight) ? trim($studentRight, " \t\n\r\0\x0B\"'") : (string)$studentRight));
                            $found = $rightIndexMap[$key] ?? null;
                            $studentPairs[] = ['left_index' => (int)$leftIndex, 'right_index' => $found];
                        }
                    }

                    // Partial scoring
                    $normCorrect = array_map(function ($p) {
                        return [
                            'left_index' => (int)$p['left_index'],
                            'right_index' => isset($p['right_index']) ? (int)$p['right_index']:null
                        ];
                    }, $correctPairs);

                    usort($normCorrect, fn($a, $b) => $a['left_index']<=> $b['left_index']);
                    usort($studentPairs, fn($a, $b) => $a['left_index'] <=> $b['left_index']);

                    $totalPairs = count($normCorrect);
                    $correctPairCount = 0;

                    foreach($studentPairs as $idx => $p) {
                        if (isset($normCorrect[$idx]) && $p['right_index'] === $normCorrect[$idx]['right_index']) {
                            $correctPairCount++;
                        }
                    }
                    $pointsEarned = $question->marks * ($totalPairs > 0 ? $correctPairCount / $totalPairs : 0);
                    $isCorrect = $correctPairCount === $totalPairs;

                    // store the student's pairs as JSON for clarity
                    $answerToStore = json_encode(['pairs' => $studentPairs, 'raw' => $studentRaw]);
                    break;

                case 'true_false':
                    $isCorrect = strtolower($studentAnswerRaw) === strtolower($correctAnswer);
                    if ($isCorrect) {
                        $pointsEarned = $question->marks;
                    }
                    break;

                case 'short_answer':

                    if ($isPractice) {
                        $confidence = $ans['confidence_score'] ?? 0;
                        $weights = [0 => 0, 1 => 0.5, 2 => 0.75, 3 => 1];
                        $pointsEarned = $question->marks * ($weights[$confidence] ?? 0);
                        $confidenceScoreToSave = $confidence;
                    } else {
                        $correctText = is_array($correctAnswer)
                            ? $correctAnswer[0]
                            : $correctAnswer;

                        $isCorrect = strtolower(trim($studentAnswerRaw)) === strtolower(trim($correctText));

                        if ($isCorrect) {
                            $pointsEarned = $question->marks;
                        }
                    }
                    break;
            }

            $totalScore += $pointsEarned;
            StudentAnswer::updateOrCreate(
                [
                    'student_assessment_id' => $studentAssessment->id,
                    'question_id' => $question->id
                ],
                [
                    'answer' => $answerToStore,
                    'is_correct' => $isCorrect,
                    'points_earned' => $pointsEarned,
                    'confidence_score' => $confidenceScoreToSave,
                    'submitted_at' => now()
                ]
            );

            if ($isPractice) {
                StudentQuestionHistory::updateOrCreate(
                    [
                        'student_id' => $studentId,
                        'question_id' => $question->id
                    ],
                    [
                        'practiced_at' => now()
                    ]
                );
            }
        }

        // 6️⃣ Finalize assessment
        $studentAssessment->update([
            'score' => $totalScore,
            'max_score' => $maxScore,
            'status' => 'completed',
            'completed_at' => now()
        ]);

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

        if ($studentAssessment->student_id !== auth::id()) {
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
                'student_id' => auth::id(),
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
