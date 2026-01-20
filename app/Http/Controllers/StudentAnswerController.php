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
            // allow empty short answers (you said it can be empty)
            'answers.*.answer' => 'nullable',
            'answers.*.confidence_score' => 'nullable|integer|min:0|max:3',
        ]);

        $studentId = auth()->id();
        $assessmentId = $request->assessment_id;

        // Helpers (closures) -------------------------------------------------------
        $parseMaybeJson = function ($value) {
            if ($value === null) return null;
            if (is_array($value) || is_object($value)) return $value;

            if (is_string($value)) {
                $t = trim($value);
                if ($t === '') return '';
                if (
                    (str_starts_with($t, '[') && str_ends_with($t, ']')) ||
                    (str_starts_with($t, '{') && str_ends_with($t, '}'))
                ) {
                    $decoded = json_decode($t, true);
                    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
                }
            }

            return $value;
        };

        $normText = function ($v) {
            return mb_strtolower(trim((string)($v ?? '')));
        };

        $toStringArray = function ($v) use ($parseMaybeJson) {
            $v = $parseMaybeJson($v);

            if ($v === null) return [];
            if (is_array($v)) {
                $isList = array_keys($v) === range(0, count($v) - 1);
                if (!$isList) return [];
                return array_map(fn($x) => (string)($x ?? ''), $v);
            }
            return [(string)$v];
        };

        // Turn MCQ student answer into normalized keys (option text)
        $normalizeStudentMcq = function ($studentRaw, array $options) use ($parseMaybeJson, $normText) {
            $studentRaw = $parseMaybeJson($studentRaw);

            $optTexts = array_map(function ($opt) {
                if (is_array($opt)) return (string)($opt['text'] ?? $opt['label'] ?? $opt['value'] ?? '');
                return (string)$opt;
            }, $options);

            $optKeys = array_map($normText, $optTexts);

            $addByIndex = function ($idx, array &$set) use ($optKeys) {
                $i = is_numeric($idx) ? (int)$idx : null;
                if ($i !== null && $i >= 0 && $i < count($optKeys)) $set[$optKeys[$i]] = true;
            };

            $addByText = function ($txt, array &$set) use ($normText) {
                $k = $normText($txt);
                if ($k !== '') $set[$k] = true;
            };

            $pick = function ($val, array &$set) use ($addByIndex, $addByText, $optTexts, $optKeys, $normText) {
                if ($val === null || $val === '') return;

                $s = (string)$val;
                $sTrim = trim($s);
                if ($sTrim === '') return;

                // ✅ If it matches an option text, treat as text (even if numeric)
                $matchIdx = array_search($sTrim, $optTexts, true);
                if ($matchIdx !== false) {
                    $addByText($sTrim, $set);
                    return;
                }

                // ✅ If numeric, only use as index when in range
                if (is_numeric($sTrim)) {
                    $i = (int)$sTrim;
                    if ($i >= 0 && $i < count($optKeys)) {
                        $addByIndex($i, $set);
                        return;
                    }
                    // else treat numeric as text
                    $addByText($sTrim, $set);
                    return;
                }

                // letter A/B/C
                if (preg_match('/^[A-Za-z]$/', $sTrim)) {
                    $idx = ord(strtoupper($sTrim)) - 65;
                    if ($idx >= 0 && $idx < count($optKeys)) $addByIndex($idx, $set);
                    else $addByText($sTrim, $set);
                    return;
                }

                // default text
                $addByText($sTrim, $set);
            };

            $set = [];

            if (is_array($studentRaw)) {
                foreach ($studentRaw as $v) $pick($v, $set);
                return array_keys($set);
            }

            $pick($studentRaw, $set);
            return array_keys($set);
        };


        // Normalize ANY matching answer to canonical pairs aligned with LEFT indices
        $normalizeMatchingPairs = function ($rawAnswer, array $leftItems, array $rightItems) use ($parseMaybeJson, $normText) {
            $rawAnswer = $parseMaybeJson($rawAnswer);

            // right text -> index map
            $rightIndexMap = [];
            foreach ($rightItems as $idx => $r) {
                $rightIndexMap[$normText($r)] = $idx;
            }

            $makeEmpty = function () use ($leftItems) {
                $out = [];
                for ($i = 0; $i < count($leftItems); $i++) {
                    $out[] = ['left_index' => $i, 'right_index' => null];
                }
                return $out;
            };

            // wrapper {pairs:[...]}
            if (is_array($rawAnswer) && array_key_exists('pairs', $rawAnswer) && is_array($rawAnswer['pairs'])) {
                $rawAnswer = $rawAnswer['pairs'];
            }

            // already pairs
            if (is_array($rawAnswer) && isset($rawAnswer[0]) && is_array($rawAnswer[0]) && (isset($rawAnswer[0]['left_index']) || isset($rawAnswer[0]['leftIndex']))) {
                $out = $makeEmpty();

                foreach ($rawAnswer as $i => $p) {
                    $li = (int)($p['left_index'] ?? $p['leftIndex'] ?? $i);
                    $ri = $p['right_index'] ?? $p['rightIndex'] ?? null;
                    $ri = ($ri === null || $ri === '') ? null : (int)$ri;

                    if ($li >= 0 && $li < count($out)) {
                        $out[$li]['right_index'] = $ri;
                    }
                }

                return $out;
            }

            // aligned array (indices OR right texts)
            if (is_array($rawAnswer)) {
                $out = $makeEmpty();

                for ($li = 0; $li < count($out); $li++) {
                    $val = $rawAnswer[$li] ?? null;

                    if ($val === null || $val === '') {
                        $out[$li]['right_index'] = null;
                        continue;
                    }

                    if (is_numeric($val)) {
                        $ri = (int)$val;
                        $out[$li]['right_index'] = ($ri >= 0 && $ri < count($rightItems)) ? $ri : null;
                        continue;
                    }

                    $k = $normText($val);
                    $out[$li]['right_index'] = $rightIndexMap[$k] ?? null;
                }

                return $out;
            }

            return $makeEmpty();
        };

        // ------------------------------------------------------------------------

        // 1) Load assessment with groups & students
        $assessment = Assessment::with('groups.students')->findOrFail($assessmentId);
        $isPractice = $assessment->type === 'practice';

        // 2) Authorization (skip for practice)
        if (!$isPractice) {
            $allowed = $assessment->groups->contains(function ($group) use ($studentId) {
                return $group->students->contains('id', $studentId);
            });

            if (!$allowed) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        // 3) Create or fetch StudentAssessment
        $studentAssessment = StudentAssessment::firstOrCreate(
            [
                'student_id' => $studentId,
                'assessment_id' => $assessmentId,
            ],
            [
                'assigned_by' => $isPractice ? $studentId : $assessment->creator_id,
                'assigned_at' => now(),
                'status' => 'in_progress',
            ]
        );

        // 4) Prevent resubmission for teacher-assigned assessments
        if (!$isPractice && $studentAssessment->status === 'completed') {
            return response()->json(['error' => 'Assessment already submitted'], 409);
        }

        $totalScore = 0.0;
        $maxScore = 0.0;

        // 5) Process answers
        foreach ($request->answers as $ans) {

            $question = Question::findOrFail($ans['question_id']);
            $studentRaw = $ans['answer'] ?? null;

            // Parse question fields
            $options = $parseMaybeJson($question->options);
            $options = is_array($options) ? $options : [];

            $correctRaw = $parseMaybeJson($question->correct_answer);

            $isCorrect = false;
            $pointsEarned = 0.0;
            $confidenceScoreToSave = null;

            $marks = (float)($question->marks ?? 0);
            $maxScore += $marks;

            // Default store
            $answerToStore = $parseMaybeJson($studentRaw);

            switch ($question->question_type) {

                case 'mcq': {
                        $studentKeys = $normalizeStudentMcq($studentRaw, $options);
                        $correctKeys = $normalizeStudentMcq($correctRaw, $options);

                        sort($studentKeys);
                        sort($correctKeys);

                        $isCorrect = ($studentKeys === $correctKeys);
                        $pointsEarned = $isCorrect ? $marks : 0.0;

                        $answerToStore = [
                            'selected_keys' => $studentKeys,
                            'raw' => $parseMaybeJson($studentRaw),
                        ];
                        break;
                    }

                case 'matching': {
                        // options expected: {left:[], right:[]}
                        $leftItems  = is_array($options['left'] ?? null) ? $options['left'] : [];
                        $rightItems = is_array($options['right'] ?? null) ? $options['right'] : [];

                        $studentPairs = $normalizeMatchingPairs($studentRaw, $leftItems, $rightItems);
                        $correctPairs = $normalizeMatchingPairs($correctRaw, $leftItems, $rightItems);

                        $totalPairs = count($leftItems);
                        $correctCount = 0;

                        for ($i = 0; $i < $totalPairs; $i++) {
                            $sr = $studentPairs[$i]['right_index'] ?? null;
                            $cr = $correctPairs[$i]['right_index'] ?? null;

                            if ($sr !== null && $cr !== null && (int)$sr === (int)$cr) {
                                $correctCount++;
                            }
                        }

                        // ✅ Per-pair scoring (this guarantees 0.5 when marks=2 and totalPairs=4)
                        $perPair = $totalPairs > 0 ? ($marks / $totalPairs) : 0.0;
                        $pointsEarned = $perPair * $correctCount;

                        // ✅ Round so you store/display 0.50 properly
                        $pointsEarned = round($pointsEarned, 2);

                        $isCorrect = ($totalPairs > 0 && $correctCount === $totalPairs);

                        // Store canonical pairs so frontend can always render correctly
                        $answerToStore = [
                            'pairs' => $studentPairs,
                        ];
                        break;
                    }

                case 'true_false': {
                        $studentVal = strtolower(trim((string)($studentRaw ?? '')));

                        $correctVal = is_array($correctRaw)
                            ? strtolower(trim((string)($correctRaw[0] ?? '')))
                            : strtolower(trim((string)($correctRaw ?? '')));

                        $isCorrect = ($studentVal !== '' && $studentVal === $correctVal);
                        $pointsEarned = $isCorrect ? $marks : 0.0;

                        $answerToStore = $studentVal; // "true"/"false"
                        break;
                    }

                case 'short_answer': {
                        $studentText = is_string($studentRaw)
                            ? trim($studentRaw)
                            : (is_null($studentRaw) ? '' : trim((string)$studentRaw));

                        if ($isPractice) {
                            $confidence = $ans['confidence_score'] ?? null;
                            $confidence = is_numeric($confidence) ? (int)$confidence : null;

                            $weights = [0 => 0, 1 => 0.5, 2 => 0.75, 3 => 1];
                            $pointsEarned = $marks * ($weights[$confidence] ?? 0);
                            $pointsEarned = round($pointsEarned, 2);

                            $confidenceScoreToSave = $confidence;

                            // optional: treat 3 as correct
                            $isCorrect = ($confidence === 3);
                        } else {
                            // exact match only if expected answer exists
                            $correctArr = $toStringArray($correctRaw);
                            $correctText = trim((string)($correctArr[0] ?? ''));

                            if ($correctText !== '' && $studentText !== '') {
                                $isCorrect = (mb_strtolower($studentText) === mb_strtolower($correctText));
                                $pointsEarned = $isCorrect ? $marks : 0.0;
                            } else {
                                $isCorrect = false;
                                $pointsEarned = 0.0;
                            }
                        }

                        $answerToStore = $studentText; // can be ""
                        break;
                    }

                default: {
                        $answerToStore = $parseMaybeJson($studentRaw);
                        $isCorrect = false;
                        $pointsEarned = 0.0;
                        break;
                    }
            }

            $totalScore += (float)$pointsEarned;

            StudentAnswer::updateOrCreate(
                [
                    'student_assessment_id' => $studentAssessment->id,
                    'question_id' => $question->id,
                ],
                [
                    'answer' => $answerToStore,
                    'is_correct' => $isCorrect,
                    'points_earned' => round((float)$pointsEarned, 2),
                    'confidence_score' => $confidenceScoreToSave,
                    'submitted_at' => now(),
                ]
            );

            if ($isPractice) {
                StudentQuestionHistory::updateOrCreate(
                    [
                        'student_id' => $studentId,
                        'question_id' => $question->id,
                    ],
                    [
                        'practiced_at' => now(),
                    ]
                );
            }
        }

        // 6) Finalize assessment
        $studentAssessment->update([
            'score' => round($totalScore, 2),
            'max_score' => round($maxScore, 2),
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Answers submitted successfully',
            'score' => round($totalScore, 2),
            'max_score' => round($maxScore, 2),
            'status' => 'completed',
            'completed_at' => $studentAssessment->completed_at,
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
