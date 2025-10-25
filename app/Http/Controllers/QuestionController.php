<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\Question;
use App\Models\GradeLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\GradeSubject;

class QuestionController extends Controller
{

    public function store(Request $request)
    {
        try {
            Log::info('Creating new question', ['user_id' => auth()->id()]);

            $validated = $this->validateQuestionData($request);
            $questionText = trim($validated['question']);
            $validated['question'] = $questionText;

            // Check for duplicate or very similar questions in the same topic
            $existingQuestions = Question::where('topic_id', $validated['topic_id'])
                ->pluck('question')
                ->map(fn($q) => trim($q))
                ->toArray();

            // Check for exact match (case-insensitive)
            $exactMatch = collect($existingQuestions)->first(function ($existing) use ($questionText) {
                return strtolower($existing) === strtolower($questionText);
            });

            if ($exactMatch) {
                return response()->json([
                    'error' => 'This exact question already exists in this topic.',
                    'duplicate_question' => $exactMatch
                ], 422);
            }

            // Handle options/correct_answer encoding for MCQ and matching
            if ($validated['question_type'] === 'mcq') {
                // Only trim options, no duplicate or similarity checks
                $validated['options'] = array_map('trim', $validated['options']);
            }
            if ($validated['question_type'] === 'matching') {
                // Decode if the frontend sent JSON strings
                if (is_string($validated['options'])) {
                    $validated['options'] = json_decode($validated['options'], true);
                }
                if (is_string($validated['correct_answer'])) {
                    $validated['correct_answer'] = json_decode($validated['correct_answer'], true);
                }

                // Encode once for DB storage
                $validated['options'] = json_encode($validated['options']);
                $validated['correct_answer'] = json_encode($validated['correct_answer']);
            }

            // Handle image upload
            if ($request->hasFile('question_image')) {
                $validated['question_image'] = $this->handleQuestionImage($request);
            }

            // Set marks based on difficulty level if not explicitly provided
            if (!isset($validated['marks']) || $validated['marks'] === 0) {
                $validated['marks'] = match ($validated['difficulty_level']) {
                    'remembering', 'understanding' => 1,
                    'analyzing' => 2,
                    'applying', 'evaluating' => 3,
                    'creating' => 4,
                    default => 1, // fallback to 1 mark
                };
            }

            // Set creator
            $validated['created_by'] = auth()->id() ?? 1;

            // Create question within transaction
            DB::beginTransaction();
            try {
                $question = Question::create($validated);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Question creation failed', ['error' => $e->getMessage()]);
                throw $e;
            }

            $question->question_image_url = $question->question_image
                ? asset('storage/' . $question->question_image)
                : null;

            return response()->json($question, 201);
        } catch (\Exception $e) {
            Log::error('Question creation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to create question',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function validateQuestionData(Request $request)
    {
        // Base rules
        $rules = [
            'topic_id' => 'required|exists:topics,id',
            'question' => 'required|string',
            'question_type' => 'required|in:mcq,true_false,short_answer,matching',
            'marks' => 'numeric|min:0',
            'difficulty_level' => 'required|in:remembering,understanding,applying,analyzing,evaluating,creating',
            'is_math' => 'required|boolean',
            'is_chemistry' => 'required|boolean',
            'multiple_answers' => 'required|boolean',
            'is_required' => 'required|boolean',
            'explanation' => 'nullable|string',
            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
        ];

        switch ($request->question_type) {
            case 'mcq':
                $rules['options'] = 'required|array|min:2';
                $rules['options.*'] = 'required|string';
                $rules['correct_answer'] = 'required|string';
                break;

            case 'true_false':
                $rules['options'] = 'required|array|size:2';
                $rules['options.*'] = 'required|string|in:true,false,True,False';
                $rules['correct_answer'] = 'required|in:true,false,True,False';
                break;

            case 'short_answer':
                $rules['options'] = 'nullable';
                $rules['correct_answer'] = 'nullable';
                break;

            case 'matching':
                // Options must be object with left[] and right[]
                $rules['options'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;

                        if (!is_array($decoded) || !isset($decoded['left']) || !isset($decoded['right'])) {
                            return $fail('Options must be an object with "left" and "right" arrays.');
                        }

                        if (!is_array($decoded['left']) || !is_array($decoded['right'])) {
                            return $fail('"left" and "right" in options must be arrays.');
                        }

                        if (count($decoded['left']) === 0 || count($decoded['right']) === 0) {
                            return $fail('"left" and "right" arrays cannot be empty.');
                        }
                    }
                ];

                // Correct answer must be array of {left_index, right_index}
                $rules['correct_answer'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;

                        if (!is_array($decoded)) {
                            return $fail('The correct_answer must be a valid JSON array.');
                        }

                        foreach ($decoded as $pair) {
                            if (!isset($pair['left_index']) || !isset($pair['right_index'])) {
                                return $fail('Each matching pair must include left_index and right_index.');
                            }

                            if (!is_int($pair['left_index']) || !is_int($pair['right_index'])) {
                                return $fail('left_index and right_index must be integers.');
                            }
                        }
                    }
                ];
                break;
        }

        return $request->validate($rules);
    }


    private function handleQuestionImage(Request $request)
    {
        $image = $request->file('question_image');
        $path = $image->store('questions', 'public');

        // Additional image validation
        if ($image->getSize() > 4096 * 1024) { // 4MB in bytes
            throw new \Exception('Image size exceeds maximum allowed size');
        }

        return $path;
    }
    public function byTopic($topicId, Request $request)
    {
        $pageSize = $request->input('page_size', 10); // Default to 10 per page
        $questions = Question::where('topic_id', $topicId)
            ->with(['topic']) // Eager load relationships
            ->paginate($pageSize);

        return response()->json([
            'success' => true,
            'data' => $questions->items(),
            'pagination' => [
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
            ],
        ]);
    }
    public function byTopicNoPagination($topicId)
    {
        $questions = Question::where('topic_id', $topicId)
            ->with('topic') // Eager load relationships
            ->get();

        return response()->json([
            'success' => true,
            'data' => $questions,
        ]);
    }
    public function allQuestions()
    {
        $questions = DB::table('questions as q')
            ->leftJoin('topics as t', 'q.topic_id', '=', 't.id')
            ->leftJoin('grade_subjects as gs', 't.grade_subject_id', '=', 'gs.id')
            ->leftJoin('subjects as s', 'gs.subject_id', '=', 's.id')
            ->leftJoin('grade_levels as g', 'gs.grade_level_id', '=', 'g.id')
            ->select(
                'q.*',
                't.topic_name',
                's.name as subject_name',
                'g.grade_name'
            )
            ->orderByDesc('q.id')
            ->get();

        return response()->json($questions);
    }
    public function update(Request $request, $id)
    {
        Log::info('Updating question with ID: ' . $id);

        // Find the question or return 404
        $question = Question::findOrFail($id);

        // Base validation rules
        $rules = [
            'topic_id' => 'sometimes|required|exists:topics,id',
            'question' => 'sometimes|required|string',
            'question_type' => 'sometimes|required|in:mcq,true_false,short_answer,matching',
            // 'options' will be set per type below
            'correct_answer' => 'sometimes|required|string',
            'marks' => 'sometimes|required|numeric|min:0',
            'difficulty_level' => 'sometimes|required|in:remembering,understanding,applying,analyzing,evaluating,creating',
            'is_math' => 'sometimes|required|boolean',
            'is_chemistry' => 'sometimes|required|boolean',
            'multiple_answers' => 'sometimes|required|boolean',
            'is_required' => 'sometimes|required|boolean',
            'explanation' => 'nullable|string',
            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
        ];

        // Type-specific validation
        switch ($request->question_type) {
            case 'mcq':
                $rules['options'] = 'sometimes|required|array|min:2';
                $rules['correct_answer'] = 'sometimes|required|string';
                break;
            case 'true_false':
                $rules['options'] = 'sometimes|required|array|min:2';
                $rules['correct_answer'] = 'sometimes|required|in:true,false,True,False';
                break;
            case 'short_answer':
                // No options required for short answer
                $rules['correct_answer'] = 'nullable|string';
                break;
            case 'matching':
                // No options required for matching
                $rules['correct_answer'] = [
                    'sometimes',
                    'required',
                    function ($attribute, $value, $fail) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;
                        if (!is_array($decoded)) {
                            return $fail('The correct_answer must be a valid JSON array.');
                        }
                        foreach ($decoded as $pair) {
                            if (!isset($pair['left']) || !isset($pair['right'])) {
                                return $fail('Each matching pair must have left and right values.');
                            }
                        }
                    }
                ];
                break;
        }

        // Validate the request
        $validated = $request->validate($rules);

        // Handle image upload
        if ($request->hasFile('question_image')) {
            if ($question->question_image) {
                Storage::disk('public')->delete($question->question_image);
            }
            $validated['question_image'] = $request->file('question_image')->store('questions', 'public');
        }

        // Encode arrays as JSON before saving
        if (isset($validated['question_type']) && $validated['question_type'] === 'matching') {
            // Store matching_items in options, matching_pairs in correct_answer
            if (isset($validated['matching_items'])) {
                $validated['options'] = is_string($validated['matching_items'])
                    ? $validated['matching_items']
                    : json_encode($validated['matching_items']);
            }
            if (isset($validated['matching_pairs'])) {
                $validated['correct_answer'] = is_string($validated['matching_pairs'])
                    ? $validated['matching_pairs']
                    : json_encode($validated['matching_pairs']);
            }
        } else {
            if (isset($validated['options']) && is_array($validated['options'])) {
                $validated['options'] = json_encode($validated['options']);
            }
            if (isset($validated['correct_answer']) && is_array($validated['correct_answer'])) {
                $validated['correct_answer'] = json_encode($validated['correct_answer']);
            }
        }

        // Update the question
        $question->update($validated);

        // Reload and decode JSON for response
        $question->refresh();
        $question->options = is_string($question->options) ? json_decode($question->options, true) : $question->options;
        $question->correct_answer = isset($question->correct_answer)
            ? (is_string($question->correct_answer) ? json_decode($question->correct_answer, true) : $question->correct_answer)
            : null;
        $question->question_image_url = $question->question_image ? asset('storage/' . $question->question_image) : null;

        return response()->json($question);
    }

    public function getQuestionCount($id)
    {
        $count = Question::where('topic_id', $id)->count();
        return response()->json(['count' => $count]);
    }

    public function show($id)
    {
        try {
            $question = Question::findOrFail($id);

            // Decode JSON fields
            $question->options = json_decode($question->options);

            // Add image URL if exists
            if ($question->question_image) {
                $question->question_image_url = asset('storage/' . $question->question_image);
            } else {
                $question->question_image_url = null;
            }

            // Include metadata with KaTeX content if it exists
            if ($question->metadata) {
                $metadata = json_decode($question->metadata, true);
                $question->katex_content = $metadata['katex_content'] ?? null;
            }

            return response()->json($question);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Question not found'], 404);
        }
    }
    public function myQuestions(Request $request)
    {
        $userId = auth()->id();
        $questions = Question::with(['topic.gradeSubject.gradeLevel', 'topic.gradeSubject.subject'])
            ->where('created_by', $userId)
            ->select('id', 'topic_id', 'question', 'options', 'question_type', 'difficulty_level', 'marks', 'correct_answer')
            ->paginate(10);

        $grouped = $questions->groupBy(function ($q) {
            return $q->topic->topic_name;
        });
        return response()->json([
            'data' => $grouped,
            'pagination' => [
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
            ],
        ]);
    }
    public function topicsWithQuestionsBySubject($subjectId)
    {
        // 1️⃣ Get all grade_subject IDs for the given subject
        $gradeSubjectIds = GradeSubject::where('subject_id', $subjectId)->pluck('id');

        // 2️⃣ Get topics under those grade_subjects, along with their questions
        $topics = Topic::whereIn('grade_subject_id', $gradeSubjectIds)
            ->with(['questions' => function ($query) {
                $query->select(
                    'id',
                    'topic_id',
                    'question',
                    'question_type',
                    'options',
                    'correct_answer',
                    'marks',
                    'difficulty_level'
                );
            }])
            ->orderBy('topic_name')
            ->get(['id', 'topic_name', 'grade_subject_id']);

        // 3️⃣ Format output for frontend filtering
        $grouped = $topics->map(function ($topic) {
            return [
                'topic_id' => $topic->id,
                'topic_name' => $topic->topic_name,
                'questions' => $topic->questions,
            ];
        });

        // 4️⃣ Return clean response
        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    public function destroy($id)
    {
        $question = Question::findOrFail($id);

        if (!$question) {
            return response()->json(['error' => 'Question not found'], 404);
        }
        if ($question->question_image) {
            Storage::disk('public')->delete($question->question_image);
        }
        $question->delete();
        return response()->json([
            'message' => 'Question deleted successfully'
        ], 200);
    }
}
