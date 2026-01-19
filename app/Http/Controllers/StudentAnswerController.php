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


    public function storeStudentAnswers_old(Request $request)
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

                    if (is_array($options) && !empty($options) && is_array($options[0])) {
                        // Options are objects (e.g., with text and image), compare indices
                        $studentAnsArr = is_array($studentAnswerRaw) ? $studentAnswerRaw : [$studentAnswerRaw];
                        $correctArrRaw = is_array($correctAnswer) ? $correctAnswer : [$correctAnswer];
                        $studentIndices = array_map(fn($sa) => (int) $sa, $studentAnsArr);
                        $correctIndices = array_map(fn($ca) => (int) $ca, $correctArrRaw);
                        sort($studentIndices);
                        sort($correctIndices);
                        $isCorrect = $studentIndices === $correctIndices;
                    } else {
                        // Options are strings, compare texts
                        $studentAnsArr = is_array($studentAnswerRaw) ? $studentAnswerRaw : [$studentAnswerRaw];
                        $studentAnsText = array_map(function ($sa) use ($options) {
                            $text = is_numeric($sa) && isset($options[$sa]) ? $options[$sa] : $sa;
                            return is_string($text) ? trim($text, " \t\n\r\0\x0B\"'") : (string) $text;
                        }, $studentAnsArr);
                        $correctArrRaw = is_array($correctAnswer) ? $correctAnswer : [$correctAnswer];
                        $correctArr = array_map(function ($ca) use ($options) {
                            if (is_numeric($ca) && isset($options[$ca])) {
                                $text = $options[$ca];
                            } else {
                                $text = is_string($ca) ? $ca : (string) $ca;
                            }
                            return trim($text, " \t\n\r\0\x0B\"'");
                        }, $correctArrRaw);
                        $normStudent = array_map(fn($v) => mb_strtolower($v), $studentAnsText);
                        $normCorrect = array_map(fn($v) => mb_strtolower($v), $correctArr);
                        sort($normStudent);
                        sort($normCorrect);
                        $isCorrect = $normStudent === $normCorrect;
                    }

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
                            'right_index' => isset($p['right_index']) ? (int)$p['right_index'] : null
                        ];
                    }, $correctPairs);

                    usort($normCorrect, fn($a, $b) => $a['left_index'] <=> $b['left_index']);
                    usort($studentPairs, fn($a, $b) => $a['left_index'] <=> $b['left_index']);

                    $totalPairs = count($normCorrect);
                    $correctPairCount = 0;

                    foreach ($studentPairs as $idx => $p) {
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
                        $confidence = $ans['confidence_score'] ?? null;
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
                // If associative (object), not a list -> ignore as list
                $isList = array_keys($v) === range(0, count($v) - 1);
                if (!$isList) return [];
                return array_map(fn($x) => (string)($x ?? ''), $v);
            }
            return [(string)$v];
        };

        // Turn MCQ student answer into normalized keys (option text)
        $normalizeStudentMcq = function ($studentRaw, array $options) use ($parseMaybeJson, $normText) {
            $studentRaw = $parseMaybeJson($studentRaw);

            // Build option texts list
            $optTexts = array_map(function ($opt) {
                if (is_array($opt)) return (string)($opt['text'] ?? $opt['label'] ?? $opt['value'] ?? '');
                return (string)$opt;
            }, $options);

            $optKeys = array_map($normText, $optTexts);

            $addByIndex = function ($idx, array &$set) use ($optKeys) {
                $i = is_numeric($idx) ? (int)$idx : null;
                if ($i !== null && $i >= 0 && $i < count($optKeys)) $set[$optKeys[$i]] = true;
            };

            $addByLetter = function ($letter, array &$set) use ($addByIndex) {
                $s = strtoupper(trim((string)$letter));
                if (preg_match('/^[A-Z]$/', $s)) {
                    $idx = ord($s) - 65;
                    $addByIndex($idx, $set);
                }
            };

            $addByText = function ($txt, array &$set) use ($normText) {
                $k = $normText($txt);
                if ($k !== '') $set[$k] = true;
            };

            $set = [];

            // If student sent an array => multi-select
            if (is_array($studentRaw)) {
                foreach ($studentRaw as $x) {
                    if (is_numeric($x)) $addByIndex($x, $set);
                    else {
                        $sx = trim((string)$x);
                        if (preg_match('/^[A-Za-z]$/', $sx)) $addByLetter($sx, $set);
                        else $addByText($sx, $set);
                    }
                }
                return array_keys($set);
            }

            // Single value
            if (is_numeric($studentRaw)) {
                $addByIndex($studentRaw, $set);
                return array_keys($set);
            }

            $sx = trim((string)$studentRaw);
            if ($sx === '') return [];
            if (preg_match('/^[A-Za-z]$/', $sx)) $addByLetter($sx, $set);
            else $addByText($sx, $set);

            return array_keys($set);
        };

        // Normalize ANY matching answer to canonical pairs [{left_index,right_index}, ...]
        $normalizeMatchingPairs = function ($rawAnswer, array $leftItems, array $rightItems) use ($parseMaybeJson, $normText) {
            $rawAnswer = $parseMaybeJson($rawAnswer);

            // Build right text -> index map
            $rightIndexMap = [];
            foreach ($rightItems as $idx => $r) {
                $rightIndexMap[$normText($r)] = $idx;
            }

            $makeEmpty = function () use ($leftItems) {
                return array_map(fn($_, $li) => ['left_index' => $li, 'right_index' => null], $leftItems, array_keys($leftItems));
            };

            // Case 1: wrapper {pairs: [...]}
            if (is_array($rawAnswer) && array_key_exists('pairs', $rawAnswer) && is_array($rawAnswer['pairs'])) {
                $rawAnswer = $rawAnswer['pairs'];
            }

            // Case 2: already pairs array
            if (is_array($rawAnswer) && isset($rawAnswer[0]) && is_array($rawAnswer[0]) && (isset($rawAnswer[0]['left_index']) || isset($rawAnswer[0]['leftIndex']))) {
                $pairs = [];
                foreach ($rawAnswer as $i => $p) {
                    $li = (int)($p['left_index'] ?? $p['leftIndex'] ?? $i);
                    $ri = $p['right_index'] ?? $p['rightIndex'] ?? null;
                    $pairs[] = [
                        'left_index' => $li,
                        'right_index' => ($ri === null || $ri === '') ? null : (int)$ri,
                    ];
                }
                // Ensure all left indices exist
                $out = $makeEmpty();
                foreach ($pairs as $p) {
                    $li = $p['left_index'];
                    if ($li >= 0 && $li < count($out)) $out[$li]['right_index'] = $p['right_index'];
                }
                return $out;
            }

            // Case 3: array aligned to left order (either indices OR right texts)
            if (is_array($rawAnswer)) {
                $out = $makeEmpty();

                foreach ($out as $li => $_p) {
                    $val = $rawAnswer[$li] ?? null;
                    if ($val === null || $val === '') {
                        $out[$li]['right_index'] = null;
                        continue;
                    }

                    // numeric index
                    if (is_numeric($val)) {
                        $ri = (int)$val;
                        $out[$li]['right_index'] = ($ri >= 0 && $ri < count($rightItems)) ? $ri : null;
                        continue;
                    }

                    // text -> find index
                    $k = $normText($val);
                    $out[$li]['right_index'] = $rightIndexMap[$k] ?? null;
                }

                return $out;
            }

            // Unknown format
            return $makeEmpty();
        };

        // ------------------------------------------------------------------------

        // 1) Load assessment with groups & students
        $assessment = Assessment::with('groups.students')->findOrFail($assessmentId);
        $isPractice = $assessment->type === 'practice';

        // 2) Authorization (skip for practice)
        if (! $isPractice) {
            $allowed = $assessment->groups->contains(function ($group) use ($studentId) {
                return $group->students->contains('id', $studentId);
            });

            if (! $allowed) {
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
        if (! $isPractice && $studentAssessment->status === 'completed') {
            return response()->json(['error' => 'Assessment already submitted'], 409);
        }

        $totalScore = 0;
        $maxScore = 0;

        // 5) Process answers
        foreach ($request->answers as $ans) {

            $question = Question::findOrFail($ans['question_id']);

            $studentRaw = $ans['answer'] ?? null;

            // Parse question fields
            $options = $parseMaybeJson($question->options);
            $options = is_array($options) ? $options : [];

            $correctRaw = $parseMaybeJson($question->correct_answer);

            $isCorrect = false;
            $pointsEarned = 0;
            $confidenceScoreToSave = null;

            $marks = (float)($question->marks ?? 0);
            $maxScore += $marks;

            // Default: store student answer as-is (JSON column will handle array/object)
            $answerToStore = $parseMaybeJson($studentRaw);

            switch ($question->question_type) {

                case 'mcq': {
                        // Normalize student selection to option text keys
                        $studentKeys = $normalizeStudentMcq($studentRaw, $options);

                        // Normalize correct to option text keys too
                        // correct can be indices, letters, or texts, or array of any
                        $correctKeys = $normalizeStudentMcq($correctRaw, $options);

                        sort($studentKeys);
                        sort($correctKeys);

                        $isCorrect = ($studentKeys === $correctKeys);

                        if ($isCorrect) {
                            $pointsEarned = $marks;
                        }

                        // Store in a stable format:
                        // - selected_keys: normalized option texts
                        // - raw: original student payload
                        $answerToStore = [
                            'selected_keys' => $studentKeys,
                            'raw' => $parseMaybeJson($studentRaw),
                        ];
                        break;
                    }

                case 'matching': {
                        // options expected: {left:[], right:[]}
                        $leftItems = is_array($options['left'] ?? null) ? $options['left'] : [];
                        $rightItems = is_array($options['right'] ?? null) ? $options['right'] : [];

                        // Canonical correct pairs should come from correct_answer (json) as pairs,
                        // otherwise we normalize whatever it is into pairs aligned with left indices.
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

                        // Partial scoring
                        // If marks=2 and 4 pairs => each pair = 0.5
                        $pointsEarned = $totalPairs > 0 ? ($marks * ($correctCount / $totalPairs)) : 0;
                        $isCorrect = ($totalPairs > 0 && $correctCount === $totalPairs);

                        // Store canonical pairs (this fixes your "No match" rendering)
                        $answerToStore = [
                            'pairs' => $studentPairs,
                        ];
                        break;
                    }

                case 'true_false': {
                        $studentVal = strtolower(trim((string)($studentRaw ?? '')));
                        $correctVal = strtolower(trim((string)(is_array($correctRaw) ? ($correctRaw[0] ?? '') : ($correctRaw ?? ''))));

                        $isCorrect = ($studentVal !== '' && $studentVal === $correctVal);

                        if ($isCorrect) {
                            $pointsEarned = $marks;
                        }

                        $answerToStore = $studentVal; // store as "true" / "false"
                        break;
                    }

                case 'short_answer': {
                        $studentText = is_string($studentRaw) ? trim($studentRaw) : (is_null($studentRaw) ? '' : trim((string)$studentRaw));

                        if ($isPractice) {
                            $confidence = $ans['confidence_score'] ?? null;
                            $confidence = is_numeric($confidence) ? (int)$confidence : null;

                            $weights = [0 => 0, 1 => 0.5, 2 => 0.75, 3 => 1];
                            $pointsEarned = $marks * ($weights[$confidence] ?? 0);
                            $confidenceScoreToSave = $confidence;

                            // In practice, "is_correct" can be false (self-graded), keep it false unless you want otherwise
                            $isCorrect = ($confidence === 3);
                        } else {
                            // Teacher-assigned: exact match ONLY if expected answer exists
                            $correctArr = $toStringArray($correctRaw);
                            $correctText = trim((string)($correctArr[0] ?? ''));

                            if ($correctText !== '' && $studentText !== '') {
                                $isCorrect = (mb_strtolower($studentText) === mb_strtolower($correctText));
                                if ($isCorrect) {
                                    $pointsEarned = $marks;
                                }
                            } else {
                                $isCorrect = false;
                                $pointsEarned = 0;
                            }
                        }

                        $answerToStore = $studentText; // can be ""
                        break;
                    }

                default: {
                        // keep default storage
                        $answerToStore = $parseMaybeJson($studentRaw);
                        $isCorrect = false;
                        $pointsEarned = 0;
                        break;
                    }
            }

            $totalScore += $pointsEarned;

            StudentAnswer::updateOrCreate(
                [
                    'student_assessment_id' => $studentAssessment->id,
                    'question_id' => $question->id,
                ],
                [
                    // JSON column: store array/object/string directly
                    'answer' => $answerToStore,
                    'is_correct' => $isCorrect,
                    // if points_earned is INT you may want round() or cast (optional):
                    'points_earned' => $pointsEarned,
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
            'score' => $totalScore,
            'max_score' => $maxScore,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Answers submitted successfully',
            'score' => $totalScore,
            'max_score' => $maxScore,
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
