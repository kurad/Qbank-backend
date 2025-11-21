<?php

namespace App\Http\Controllers;

use App\Models\Topic;
use App\Models\Question;
use App\Models\GradeLevel;
use App\Models\GradeSubject;
use Illuminate\Http\Request;
use App\Services\GroqAIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class QuestionController extends Controller
{

    protected $groqAI;
    public function __construct(GroqAIService $groqAI)
    {
        $this->groqAI = $groqAI;
    }
    public function store(Request $request)
    {
        try {
            Log::info('Creating new question', ['user_id' => auth()->id()]);

            $validated = $this->validateQuestionData($request);
            $questionText = trim($validated['question']);
            $validated['question'] = $questionText;

            // Only check for duplicate question text for non-matching types
            if ($validated['question_type'] !== 'matching') {
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
            }

            // Handle options/correct_answer encoding for MCQ and matching
            if ($validated['question_type'] === 'mcq') {
                // Normalize MCQ options: combine text options[] with optional option_images[] files
                $textOptions = $validated['options'] ?? [];
                $imageFiles = $request->file('option_images', []);

                $normalizedOptions = [];

                foreach ($textOptions as $index => $text) {
                    $text = is_string($text) ? trim($text) : '';
                    $imagePath = null;

                    if (isset($imageFiles[$index]) && $imageFiles[$index]) {
                        $imageFile = $imageFiles[$index];
                        $imagePath = $imageFile->store('options', 'public');
                    }

                    $normalizedOptions[] = [
                        'text' => $text !== '' ? $text : null,
                        'image' => $imagePath,
                    ];
                }

                $validated['options'] = $normalizedOptions;
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
            // For matching and short_answer, always respect user-provided marks
            if (!in_array($validated['question_type'], ['matching', 'short_answer'], true)) {
                if (!isset($validated['marks']) || $validated['marks'] === 0) {
                    $validated['marks'] = match ($validated['difficulty_level']) {
                        'remembering', 'understanding' => 1,
                        'analyzing' => 2,
                        'applying', 'evaluating' => 3,
                        'creating' => 4,
                        default => 1, // fallback to 1 mark
                    };
                }
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
                // Options can be text, image, or both. Ensure each index has at least one.
                $rules['options'] = [
                    'required',
                    'array',
                    'min:2',
                    function ($attribute, $value, $fail) use ($request) {
                        $imageFiles = $request->file('option_images', []);

                        if (!is_array($value) || count($value) < 2) {
                            return $fail('At least two options are required.');
                        }

                        foreach ($value as $index => $text) {
                            $hasText = is_string($text) && trim($text) !== '';
                            $hasImage = isset($imageFiles[$index]) && $imageFiles[$index];

                            if (!$hasText && !$hasImage) {
                                return $fail("Each option must have either text or an image.");
                            }
                        }
                    }
                ];

                // Optional image per option, aligned by index with options[]
                $rules['option_images'] = 'nullable|array';
                $rules['option_images.*'] = 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096';

                // Allow correct_answer as either index or option text (backwards compatible)
                $rules['correct_answer'] = [
                    'required',
                    function ($attribute, $value, $fail) use ($request) {
                        $options = $request->input('options', []);

                        if (!is_array($options) || empty($options)) {
                            return $fail('Options must be provided before selecting a correct answer.');
                        }

                        $valueStr = (string) $value;

                        // If numeric, treat as index into options
                        if (ctype_digit($valueStr)) {
                            $index = (int) $valueStr;
                            if (!array_key_exists($index, $options)) {
                                return $fail('The selected correct answer index is invalid.');
                            }
                            return;
                        }

                        // Otherwise, treat as option text for backward compatibility
                        $trimmedOptions = array_map(function ($opt) {
                            return is_string($opt) ? trim($opt) : $opt;
                        }, $options);

                        if (!in_array($value, $trimmedOptions, true)) {
                            return $fail('The selected correct answer does not match any option text.');
                        }
                    }
                ];
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

        // Normalize options/correct_answer for each question in the page
        $normalizedItems = $questions->getCollection()->map(function ($question) {
            return $this->normalizeQuestionPayload($question);
        })->values();

        return response()->json([
            'success' => true,
            'data' => $normalizedItems,
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

        // Normalize options/correct_answer for all questions
        $normalized = $questions->map(function ($question) {
            return $this->normalizeQuestionPayload($question);
        })->values();

        return response()->json([
            'success' => true,
            'data' => $normalized,
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
            //->where('q.created_by', auth()->id())
            ->orderByDesc('q.id')
            ->get();

        // Map collection to apply normalization similar to Eloquent models
        $normalized = $questions->map(function ($q) {
            return $this->normalizeRawQuestionPayload($q);
        })->values();

        return response()->json($normalized);
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

        // Set marks based on difficulty level if not explicitly provided (same as store)
        // For matching and short_answer, always respect user-provided marks
        $effectiveType = $validated['question_type'] ?? $question->question_type;
        if (!in_array($effectiveType, ['matching', 'short_answer'], true)) {
            if ((!isset($validated['marks']) || $validated['marks'] === 0) && isset($validated['difficulty_level'])) {
                $validated['marks'] = match ($validated['difficulty_level']) {
                    'remembering', 'understanding' => 1,
                    'analyzing' => 2,
                    'applying', 'evaluating' => 3,
                    'creating' => 4,
                    default => 1,
                };
            }
        }

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

            $normalizedQuestion = $this->normalizeQuestionPayload($question);

            return response()->json($normalizedQuestion);
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

        // Normalize question payloads before grouping
        $normalizedCollection = $questions->getCollection()->map(function ($q) {
            return $this->normalizeQuestionPayload($q);
        });

        $grouped = $normalizedCollection->groupBy(function ($q) {
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

        // 3️⃣ Format output for frontend filtering and normalize question payloads
        $grouped = $topics->map(function ($topic) {
            $normalizedQuestions = $topic->questions->map(function ($q) {
                return $this->normalizeQuestionPayload($q);
            })->values();

            return [
                'topic_id' => $topic->id,
                'topic_name' => $topic->topic_name,
                'questions' => $normalizedQuestions,
            ];
        });

        // 4️⃣ Return clean response
        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    public function search(Request $request)
    {
        $search = $request->input('search');
        $topicId = $request->input('topic_id');
        $subjectId = $request->input('subject_id');
        $gradeLevelId = $request->input('grade_level_id');
        $questionType = $request->input('question_type');
        $difficulty = $request->input('difficulty_level');
        $createdBy = $request->input('created_by');
        $pageSize = $request->input('page_size', 10);

        $query = Question::with(['topic.gradeSubject.gradeLevel', 'topic.gradeSubject.subject']);

        if (!empty($search)) {
            $query->where('question', 'like', "%{$search}%");
        }

        if (!empty($topicId)) {
            $query->where('topic_id', $topicId);
        }

        if (!empty($subjectId)) {
            $query->whereHas('topic.gradeSubject', function ($q) use ($subjectId) {
                $q->where('subject_id', $subjectId);
            });
        }

        if (!empty($gradeLevelId)) {
            $query->whereHas('topic.gradeSubject.gradeLevel', function ($q) use ($gradeLevelId) {
                $q->where('id', $gradeLevelId);
            });
        }

        if (!empty($questionType)) {
            $query->where('question_type', $questionType);
        }

        if (!empty($difficulty)) {
            $query->where('difficulty_level', $difficulty);
        }

        if (!empty($createdBy)) {
            $query->where('created_by', $createdBy);
        }

        $questions = $query->orderByDesc('id')->paginate($pageSize);

        $normalizedItems = $questions->getCollection()->map(function ($question) {
            return $this->normalizeQuestionPayload($question);
        })->values();

        return response()->json([
            'success' => true,
            'data' => $normalizedItems,
            'pagination' => [
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
            ],
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
    
    // Normalize options and correct_answer values that may be JSON-like strings
    private function decodeJsonIfNeeded($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        // Only attempt decode for JSON-looking strings
        if (($trimmed[0] === '[' && substr($trimmed, -1) === ']') ||
            ($trimmed[0] === '{' && substr($trimmed, -1) === '}')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    // Normalize a Question Eloquent model for API responses
    private function normalizeQuestionPayload($question)
    {
        // Ensure options is decoded from outer JSON first
        if (is_string($question->options)) {
            $outer = json_decode($question->options, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $question->options = $outer;
            }
        }

        // Normalize inner option values that might be JSON-like strings
        if (is_array($question->options)) {
            $question->options = array_map(function ($opt) {
                return $this->decodeJsonIfNeeded($opt);
            }, $question->options);
        }

        // Normalize correct_answer similarly
        if (isset($question->correct_answer)) {
            $question->correct_answer = $this->decodeJsonIfNeeded($question->correct_answer);
        }

        // Add image URL if exists
        if (!empty($question->question_image)) {
            $question->question_image_url = asset('storage/' . $question->question_image);
        } else {
            $question->question_image_url = null;
        }

        // Include metadata with KaTeX content if it exists
        if (!empty($question->metadata)) {
            $metadata = is_string($question->metadata)
                ? json_decode($question->metadata, true)
                : $question->metadata;
            if (is_array($metadata)) {
                $question->katex_content = $metadata['katex_content'] ?? null;
            }
        }

        return $question;
    }

    // Normalize a raw DB row (stdClass) from query builder
    private function normalizeRawQuestionPayload($row)
    {
        // Decode outer JSON for options
        if (isset($row->options) && is_string($row->options)) {
            $outer = json_decode($row->options, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $row->options = $outer;
            }
        }

        if (isset($row->options) && is_array($row->options)) {
            $row->options = array_map(function ($opt) {
                return $this->decodeJsonIfNeeded($opt);
            }, $row->options);
        }

        if (isset($row->correct_answer)) {
            $row->correct_answer = $this->decodeJsonIfNeeded($row->correct_answer);
        }

        // Derived image URL if question_image exists
        if (isset($row->question_image) && !empty($row->question_image)) {
            $row->question_image_url = asset('storage/' . $row->question_image);
        } else {
            $row->question_image_url = null;
        }

        return $row;
    }
    
    // Helper: detect presence of common LaTeX/math markers in a string
    private function containsLatexMath(string $text): bool
    {
        // We treat typical LaTeX markers and caret-based math as "mathy"
        $needles = ['\\\(', '\\\)', '$', '\\frac', '\\sqrt', '\\sum', '\\int', '^'];

        foreach ($needles as $needle) {
            if (strpos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // Helper: check if any option string looks like math
    private function optionsContainLatexMath(array $options): bool
    {
        foreach ($options as $opt) {
            if (is_string($opt) && $this->containsLatexMath($opt)) {
                return true;
            }
            if (is_array($opt) && isset($opt['text']) && is_string($opt['text']) && $this->containsLatexMath($opt['text'])) {
                return true;
            }
        }

        return false;
    }

    // Helper: ensure text is wrapped in LaTeX inline math delimiters $...$
    // Safely converts existing \(...\) to $...$ and avoids double-wrapping
    private function wrapInlineLatex(string $text): string
    {
        $trimmed = trim($text);

        // Already wrapped in $...$
        if (preg_match('/^\$.*\$$/s', $trimmed)) {
            return $trimmed;
        }

        // If wrapped in \(...\), convert to $...$
        if (preg_match('/^\\\((.*)\\\)$/s', $trimmed, $m)) {
            return '$' . $m[1] . '$';
        }

        return '$' . $trimmed . '$';
    }
    private function normalizeMathOptions(array $options): array
    {
        return array_map(function ($opt) {
            // String option
            if (is_string($opt)) {
                $trimmed = trim($opt);

                if ($this->containsLatexMath($trimmed)) {
                    return $this->wrapInlineLatex($trimmed);
                }

                return $trimmed;
            }

            // Object-like option with 'text' field
            if (is_array($opt) && isset($opt['text']) && is_string($opt['text'])) {
                $text = trim($opt['text']);

                if ($this->containsLatexMath($text)) {
                    $opt['text'] = $this->wrapInlineLatex($text);
                } else {
                    $opt['text'] = $text;
                }

                return $opt;
            }

            return $opt;
        }, $options);
    }

    private function normalizeMathQuestion(string $question): string
    {
        $trimmed = trim($question);

        // If it doesn't look mathy at all, return as-is
        if (!$this->containsLatexMath($trimmed)) {
            return $trimmed;
        }

        // If the whole string is already a single math expression (no spaces or very few words),
        // treat it as a pure expression and wrap it entirely.
        $wordCount = str_word_count($trimmed);
        if ($wordCount <= 2 && !preg_match('/[\.!?]/', $trimmed)) {
            return $this->wrapInlineLatex($trimmed);
        }

        // For sentence-like text that contains math fragments (e.g. "2^x", "2^4"),
        // wrap only those fragments in $...$ while leaving the rest as plain text.
        $wrapped = preg_replace_callback(
            // Match simple power expressions like 2^x, x^2, (x+1)^2 etc. without spaces
            '/([A-Za-z0-9()]+\^[A-Za-z0-9()]+)/',
            function ($m) {
                return $this->wrapInlineLatex($m[1]);
            },
            $trimmed
        );

        return $wrapped !== null ? $wrapped : $trimmed;
    }
    public function generateAIQuestions(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|max:2000',
            'topic_id' => 'required|exists:topics,id',
            'question_type' => 'required|in:mcq,true_false,short_answer,matching',
            'difficulty_level' => 'required|in:remembering,understanding,applying,analyzing,evaluating,creating',
        ]);
        try {
            $prompt = $request->prompt;
            $aiResponse = $this->groqAI->generateQuestions($prompt);

            $generatedQuestions = json_decode($aiResponse, true);
            if (!$generatedQuestions) {
                $generatedQuestions = [
                    [
                        'question' => $aiResponse,
                        'question_type' => $request->question_type ?? 'short_answer',
                        'difficulty_level' => $request->difficulty_level ?? 'remembering',
                        'topic_id' => $request->topic_id,
                        'created_by' => auth()->id() ?? 1,
                    ]
                ];
            } else {
                // Add topic_id, created_by, and ensure correct_answer for each AI question
                foreach ($generatedQuestions as &$q) {
                    $q['topic_id'] = $request->topic_id;
                    $q['created_by'] = auth()->id() ?? 1;

                    // If options came from AI as a JSON string, decode once here
                    if (isset($q['options']) && is_string($q['options'])) {
                        $decodedOptions = json_decode($q['options'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedOptions)) {
                            $q['options'] = $decodedOptions;
                        }
                    }

                    // Detect math (LaTeX) via question and/or options
                    $hasMathInQuestion = isset($q['question']) && $this->containsLatexMath($q['question']);
                    $hasMathInOptions = isset($q['options']) && is_array($q['options']) && $this->optionsContainLatexMath($q['options']);

                    if ($hasMathInQuestion && isset($q['question'])) {
                        $q['question'] = $this->normalizeMathQuestion($q['question']);
                    }

                    if ($hasMathInOptions && isset($q['options']) && is_array($q['options'])) {
                        $q['options'] = $this->normalizeMathOptions($q['options']);
                    }

                    if ($hasMathInQuestion || $hasMathInOptions) {
                        $q['is_math'] = true;
                    } else {
                        $q['is_math'] = false;
                    }

                    // Ensure correct_answer is set for each type
                    $type = $q['question_type'] ?? $request->question_type;
                    if ($type === 'mcq') {
                        // Decode options if needed
                        if (isset($q['options']) && is_string($q['options'])) {
                            $q['options'] = json_decode($q['options'], true);
                        }
                        // If no correct_answer, pick the first option
                        if (empty($q['correct_answer']) && !empty($q['options']) && is_array($q['options'])) {
                            $q['correct_answer'] = is_array($q['options'][0]) && isset($q['options'][0]['text']) ? $q['options'][0]['text'] : $q['options'][0];
                        }
                    } elseif ($type === 'true_false') {
                        if (empty($q['correct_answer'])) {
                            $q['correct_answer'] = 'True';
                        }
                        $q['options'] = ['True', 'False'];
                    }
                }
            }
            return response()->json([
                'success' => true,
                'data' => $generatedQuestions,
            ]);
        } catch (\Exception $e) {
            Log::error('AI question generation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate questions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a selected AI-generated question in the database
     */
    public function storeAIQuestions(Request $request)
    {
        // Validate as if coming from the frontend selection
        $validated = $this->validateQuestionData($request);
        $questionText = trim($validated['question']);
        $validated['question'] = $questionText;

        // Detect math (LaTeX) from question and/or options
        $hasMathInQuestion = isset($validated['question']) && $this->containsLatexMath($validated['question']);
        $hasMathInOptions = isset($validated['options']) && is_array($validated['options']) && $this->optionsContainLatexMath($validated['options']);

        if ($hasMathInQuestion) {
            $validated['question'] = $this->normalizeMathQuestion($validated['question']);
        }

        if ($hasMathInOptions && isset($validated['options']) && is_array($validated['options'])) {
            $validated['options'] = $this->normalizeMathOptions($validated['options']);
        }

        if ($hasMathInQuestion || $hasMathInOptions) {
            $validated['is_math'] = true;
        } else {
            $validated['is_math'] = false;
        }

        // Handle options/correct_answer encoding for MCQ and matching
        if ($validated['question_type'] === 'mcq') {
            // Normalize MCQ options: combine text options[] with optional option_images[] files
            $textOptions = $validated['options'] ?? [];
            $imageFiles = $request->file('option_images', []);

            $normalizedOptions = [];

            foreach ($textOptions as $index => $text) {
                $text = is_string($text) ? trim($text) : '';
                $imagePath = null;

                if (isset($imageFiles[$index]) && $imageFiles[$index]) {
                    $imageFile = $imageFiles[$index];
                    $imagePath = $imageFile->store('options', 'public');
                }

                $normalizedOptions[] = [
                    'text' => $text !== '' ? $text : null,
                    'image' => $imagePath,
                ];
            }

            $validated['options'] = $normalizedOptions;
        }
        // Handle image upload (optional, not expected from AI)
        if ($request->hasFile('question_image')) {
            $validated['question_image'] = $this->handleQuestionImage($request);
        }

        // Set marks based on difficulty level if not explicitly provided
        // For matching and short_answer, always respect user-provided marks
        if (!in_array($validated['question_type'], ['matching', 'short_answer'], true)) {
            if (!isset($validated['marks']) || $validated['marks'] === 0) {
                $validated['marks'] = match ($validated['difficulty_level']) {
                    'remembering', 'understanding' => 1,
                    'analyzing' => 2,
                    'applying', 'evaluating' => 3,
                    'creating' => 4,
                    default => 1,
                };
            }
        }

        $validated['created_by'] = auth()->id() ?? 1;

        DB::beginTransaction();
        try {
            $question = Question::create($validated);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AI question save failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to save question',
                'details' => $e->getMessage()
            ], 500);
        }

        $question->question_image_url = $question->question_image
            ? asset('storage/' . $question->question_image)
            : null;

        return response()->json($question, 201);
    }
}
