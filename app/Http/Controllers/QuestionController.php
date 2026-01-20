<?php

namespace App\Http\Controllers;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class QuestionController extends Controller
{
    public function store(Request $request)
    {
        try {
            Log::info('Creating new question', ['user_id' => auth()->id()]);

            // 1) Normalize payload (AI + manual)
            $payload = $this->normalizeQuestionPayload($request->all());
            $request->replace($payload);

            // 2) Validate parent
            $validated = $this->validateParentQuestion($request);

            // 3) Transaction save (parent + subquestions)
            $question = DB::transaction(function () use ($validated, $request) {

                $hasSub = is_array($request->input('sub_questions')) && count($request->input('sub_questions')) > 0;

                $data = $validated;

                if ($hasSub) {
                    // container question marker (optional)
                    $data['is_container'] = true;
                }

                /** @var Question $question */
                $question = Question::create($data);

                // Store subquestions if present
                if ($hasSub) {
                    foreach ($request->input('sub_questions', []) as $index => $sub) {

                        $sub = $this->normalizeQuestionPayload($sub);
                        $subValidated = $this->validateSubQuestion($sub, $index);

                        // parent-child relation
                        $subValidated['parent_id'] = $question->id;

                        // If you want sub-questions to inherit topic/grade/subject by default:
                        $subValidated['topic_id'] = $subValidated['topic_id'] ?? $question->topic_id;
                        $subValidated['grade_level_id'] = $subValidated['grade_level_id'] ?? $question->grade_level_id;
                        $subValidated['grade_subject_id'] = $subValidated['grade_subject_id'] ?? $question->grade_subject_id;

                        Question::create($subValidated);
                    }
                }

                return $question;
            });

            return response()->json([
                'message' => 'Question created successfully',
                'data' => $question->fresh(),
            ], 201);

        } catch (ValidationException $e) {
            $count = $e->validator->errors()->count();
            $first = $e->validator->errors()->first();

            return response()->json([
                'error' => 'Failed to create question',
                'details' => $first . ' (and ' . max(0, $count - 1) . ' more error' . (($count - 1) === 1 ? '' : 's') . ')',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Question creation failed', [
                'user_id' => auth()->id(),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create question',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Normalize payload so AI + manual inputs match:
     * - correct_answer MUST be array for non-short_answer
     * - for short_answer: allow null/missing -> store as []
     */
    private function normalizeQuestionPayload(array $data): array
    {
        // Normalize question_type
        if (isset($data['question_type']) && is_string($data['question_type'])) {
            $data['question_type'] = strtolower(trim($data['question_type']));
        }

        // Trim text fields
        foreach (['question_text', 'explanation', 'hint'] as $f) {
            if (isset($data[$f]) && is_string($data[$f])) {
                $data[$f] = trim($data[$f]);
            }
        }

        // Normalize options
        if (array_key_exists('options', $data)) {
            $data['options'] = $this->forceArray($data['options']);
        }

        // Normalize sub_questions
        if (array_key_exists('sub_questions', $data)) {
            $data['sub_questions'] = $this->forceArray($data['sub_questions']);
        }

        // ✅ Key normalization: correct_answer
        $type = $data['question_type'] ?? null;

        if ($type === 'short_answer') {
            // allow null/missing; store as []
            if (!array_key_exists('correct_answer', $data) || $data['correct_answer'] === null || $data['correct_answer'] === '') {
                $data['correct_answer'] = [];
            } else {
                $data['correct_answer'] = $this->cleanArray($this->forceArray($data['correct_answer']));
            }
        } else {
            // non-short_answer: force array (even if AI sends "A")
            if (array_key_exists('correct_answer', $data)) {
                $data['correct_answer'] = $this->cleanArray($this->forceArray($data['correct_answer']));
            }
        }

        return $data;
    }

    /**
     * Convert various formats into array:
     * - array -> array
     * - JSON string -> decoded array
     * - scalar -> [scalar]
     * - null -> []
     */
    private function forceArray($value): array
    {
        if (is_array($value)) return $value;

        if ($value === null) return [];

        if (is_string($value)) {
            $trim = trim($value);

            // JSON list/object?
            if ($trim !== '' && (str_starts_with($trim, '[') || str_starts_with($trim, '{'))) {
                $decoded = json_decode($trim, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return is_array($decoded) ? $decoded : [$decoded];
                }
            }

            // plain string
            return [$trim];
        }

        // number/bool/object
        return [$value];
    }

    private function cleanArray(array $arr): array
    {
        $arr = array_map(function ($v) {
            return is_string($v) ? trim($v) : $v;
        }, $arr);

        $arr = array_values(array_filter($arr, function ($v) {
            return !($v === null || $v === '');
        }));

        return $arr;
    }

    /**
     * Parent validation:
     * - correct_answer required for non-short_answer
     * - correct_answer nullable for short_answer
     */
    private function validateParentQuestion(Request $request): array
    {
        $type = $request->input('question_type');

        return $request->validate([
            'question_text' => ['required', 'string', 'min:3'],
            'question_type' => ['required', 'string'],

            'difficulty' => ['nullable', 'string'],
            'marks' => ['nullable', 'integer', 'min:1'],

            'options' => ['nullable', 'array'],

            // ✅ conditional rule
            'correct_answer' => [
                $type === 'short_answer' ? 'nullable' : 'required',
                'array',
                function ($attribute, $value, $fail) use ($type) {
                    // For non-short_answer, enforce min 1 manually (because nullable exists for short_answer)
                    if ($type !== 'short_answer' && (empty($value) || count($value) < 1)) {
                        $fail('The correct answer field is required for this question type.');
                    }
                }
            ],

            'topic_id' => ['required', 'integer'],
            'grade_level_id' => ['nullable', 'integer'],
            'grade_subject_id' => ['nullable', 'integer'],

            'explanation' => ['nullable', 'string'],
            'hint' => ['nullable', 'string'],

            'sub_questions' => ['nullable', 'array'],
        ]);
    }

    /**
     * Sub-question validation with index-aware errors.
     */
    private function validateSubQuestion(array $sub, int $index): array
    {
        $type = $sub['question_type'] ?? null;

        $validator = \Validator::make($sub, [
            'question_text' => ['required', 'string', 'min:3'],
            'question_type' => ['required', 'string'],

            'difficulty' => ['nullable', 'string'],
            'marks' => ['nullable', 'integer', 'min:1'],

            'options' => ['nullable', 'array'],

            // ✅ same conditional rule for subquestions
            'correct_answer' => [
                $type === 'short_answer' ? 'nullable' : 'required',
                'array',
                function ($attribute, $value, $fail) use ($type) {
                    if ($type !== 'short_answer' && (empty($value) || count($value) < 1)) {
                        $fail('The correct answer field is required for this question type.');
                    }
                }
            ],

            'topic_id' => ['nullable', 'integer'],
            'grade_level_id' => ['nullable', 'integer'],
            'grade_subject_id' => ['nullable', 'integer'],

            'explanation' => ['nullable', 'string'],
            'hint' => ['nullable', 'string'],
        ], [], [
            'question_text' => "sub_questions.$index.question_text",
            'correct_answer' => "sub_questions.$index.correct_answer",
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return $validator->validated();
    }
}
