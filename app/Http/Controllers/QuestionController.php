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

            // ---------------------------------------
            // Detect if parent has sub-questions
            // ---------------------------------------
            $subQuestionsInput = $request->input('sub_questions');
            $hasSub = is_array($subQuestionsInput) && count($subQuestionsInput) > 0;

            if ($hasSub) {
                // Convert the parent into a "container" question
                $request->merge([
                    'question_type' => 'parent',
                    'options' => null,
                    'correct_answer' => null,
                    'marks' => null,
                    'multiple_answers' => false,
                    'is_required' => false,
                    'is_math' => false,
                    'is_chemistry' => false,
                ]);
            }

            // Validate main question
            $validated = $this->validateQuestionData($request);

            // Normalize question text
            if (isset($validated['question']) && is_string($validated['question'])) {
                $validated['question'] = trim($validated['question']);
            }
            $questionText = $validated['question'] ?? '';

            // Prevent duplicate questions ONLY for non-parent, non-matching
            if (! $hasSub && (($validated['question_type'] ?? null) !== 'matching')) {
                $existingQuestions = Question::where('topic_id', $validated['topic_id'])
                    ->pluck('question')
                    ->map(fn($q) => trim($q))
                    ->toArray();

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

            // ---------------------------------------
            // Handle MCQ normalization (only if not parent)
            // options is JSON column + cast to array => keep as array
            // ---------------------------------------
            if (! $hasSub && (($validated['question_type'] ?? null) === 'mcq')) {
                $textOptions = $validated['options'] ?? [];
                $imageFiles  = $request->file('option_images', []);

                $normalizedOptions = [];

                foreach ($textOptions as $index => $text) {
                    $text = is_string($text) ? trim($text) : '';
                    $imagePath = null;

                    if (isset($imageFiles[$index]) && $imageFiles[$index]) {
                        $imagePath = $imageFiles[$index]->store('options', 'public');
                    }

                    $normalizedOptions[] = [
                        'text'  => $text !== '' ? $text : null,
                        'image' => $imagePath,
                    ];
                }

                $validated['options'] = $normalizedOptions; // keep array
            }

            // ---------------------------------------
            // Matching question processing (KEEP ARRAYS)
            // If frontend sends JSON strings, decode, then store array
            // ---------------------------------------
            if (! $hasSub && (($validated['question_type'] ?? null) === 'matching')) {
                if (isset($validated['options']) && is_string($validated['options'])) {
                    $validated['options'] = json_decode($validated['options'], true);
                }
                if (isset($validated['correct_answer']) && is_string($validated['correct_answer'])) {
                    $validated['correct_answer'] = json_decode($validated['correct_answer'], true);
                }

                $validated['options'] = is_array($validated['options'] ?? null) ? $validated['options'] : [];
                $validated['correct_answer'] = is_array($validated['correct_answer'] ?? null) ? $validated['correct_answer'] : [];
            }

            // ---------------------------------------
            // Optional normalization for MCQ / True-False correct_answer
            // Keep consistent storage as array (since correct_answer is json + cast array)
            // ---------------------------------------
            if (! $hasSub && in_array(($validated['question_type'] ?? null), ['mcq', 'true_false'], true)) {
                if (array_key_exists('correct_answer', $validated) && ! is_array($validated['correct_answer'])) {
                    $validated['correct_answer'] = [$validated['correct_answer']];
                }
            }

            // ---------------------------------------
            // Handle parent-question overrides
            // ---------------------------------------
            if ($hasSub) {
                $validated['options'] = null;
                $validated['correct_answer'] = null;
                $validated['marks'] = null; // parent has no marks
            }

            // Image upload (works for both parent & normal)
            if ($request->hasFile('question_image')) {
                $validated['question_image'] = $this->handleQuestionImage($request);
            }

            // Correct answer image upload for short_answer
            if (! $hasSub && (($validated['question_type'] ?? null) === 'short_answer') && $request->hasFile('correct_answer_image')) {
                $validated['correct_answer_image'] = $request->file('correct_answer_image')->store('answers', 'public');
            }

            // Auto-assign marks only for normal questions
            if (! $hasSub && ! in_array(($validated['question_type'] ?? null), ['matching', 'short_answer'], true)) {
                if (! isset($validated['marks']) || (float)$validated['marks'] === 0.0) {
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

            // ---------------------------------------
            // Transaction: create parent + sub-questions
            // ---------------------------------------
            DB::beginTransaction();

            try {
                $question = Question::create($validated);

                // SUB QUESTIONS
                if ($hasSub) {
                    $baseForSub = [
                        'topic_id' => $validated['topic_id'],
                        'difficulty_level' => $validated['difficulty_level'],
                        'is_math' => $validated['is_math'],
                        'is_chemistry' => $validated['is_chemistry'],
                        'multiple_answers' => $validated['multiple_answers'],
                        'is_required' => $validated['is_required'],
                        'parent_question_id' => $question->id,
                        'created_by' => $validated['created_by'],
                    ];

                    foreach ($request->input('sub_questions', []) as $subData) {
                        if (! is_array($subData)) {
                            continue;
                        }

                        $subRequest = new Request(array_merge($baseForSub, $subData));
                        $subValidated = $this->validateQuestionData($subRequest);

                        // Normalize sub question text
                        if (isset($subValidated['question']) && is_string($subValidated['question'])) {
                            $subValidated['question'] = trim($subValidated['question']);
                        }

                        // Sub MCQ options normalization (no images here unless you implement it)
                        if (($subValidated['question_type'] ?? null) === 'mcq') {
                            if (isset($subValidated['options']) && is_string($subValidated['options'])) {
                                $decoded = json_decode($subValidated['options'], true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $subValidated['options'] = $decoded;
                                }
                            }
                            $subValidated['options'] = is_array($subValidated['options'] ?? null) ? $subValidated['options'] : [];
                        }

                        // Sub matching normalization (KEEP ARRAYS)
                        if (($subValidated['question_type'] ?? null) === 'matching') {
                            if (isset($subValidated['options']) && is_string($subValidated['options'])) {
                                $subValidated['options'] = json_decode($subValidated['options'], true);
                            }
                            if (isset($subValidated['correct_answer']) && is_string($subValidated['correct_answer'])) {
                                $subValidated['correct_answer'] = json_decode($subValidated['correct_answer'], true);
                            }

                            $subValidated['options'] = is_array($subValidated['options'] ?? null) ? $subValidated['options'] : [];
                            $subValidated['correct_answer'] = is_array($subValidated['correct_answer'] ?? null) ? $subValidated['correct_answer'] : [];
                        }

                        // Optional normalization for MCQ / True-False correct_answer in sub
                        if (in_array(($subValidated['question_type'] ?? null), ['mcq', 'true_false'], true)) {
                            if (array_key_exists('correct_answer', $subValidated) && ! is_array($subValidated['correct_answer'])) {
                                $subValidated['correct_answer'] = [$subValidated['correct_answer']];
                            }
                        }

                        // Auto-assign marks for non-matching and non-short_answer
                        if (! in_array(($subValidated['question_type'] ?? null), ['matching', 'short_answer'], true)) {
                            if (! isset($subValidated['marks']) || (float)$subValidated['marks'] === 0.0) {
                                $subValidated['marks'] = match ($subValidated['difficulty_level']) {
                                    'remembering', 'understanding' => 1,
                                    'analyzing' => 2,
                                    'applying', 'evaluating' => 3,
                                    'creating' => 4,
                                    default => 1,
                                };
                            }
                        }

                        $subValidated['created_by'] = $validated['created_by'];
                        $subValidated['parent_question_id'] = $question->id;

                        Question::create($subValidated);
                    }
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Question creation failed', ['error' => $e->getMessage()]);
                throw $e;
            }

            // Response (casts already give arrays; appends cover URLs)
            $question->refresh();

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
    private function validateQuestionData_old(Request $request)
    {
        $rules = [
            'topic_id' => 'required|exists:topics,id',
            'question' => 'required|string',
            'question_type' => 'required|in:mcq,true_false,short_answer,matching,parent',
            'marks' => 'numeric|min:0|nullable',
            'difficulty_level' => 'required|in:remembering,understanding,applying,analyzing,evaluating,creating',
            'is_math' => 'required|boolean',
            'is_chemistry' => 'required|boolean',
            'multiple_answers' => 'required|boolean',
            'is_required' => 'required|boolean',
            'explanation' => 'nullable|string',
            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
            'correct_answer_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
            'parent_question_id' => 'nullable|exists:questions,id',
        ];

        // ---------------------------------------
        // Parent question = no options, no answers
        // ---------------------------------------
        if ($request->question_type === 'parent') {
            $rules['options'] = 'nullable';
            $rules['correct_answer'] = 'nullable';
            return $request->validate($rules);
        }

        // ---------------------------------------
        // Normal question types
        // ---------------------------------------
        switch ($request->question_type) {
            case 'mcq':
                $rules['options'] = [
                    'required',
                    'array',
                    'min:2',
                    function ($attribute, $value, $fail) use ($request) {
                        $imageFiles = $request->file('option_images', []);
                        foreach ($value as $index => $text) {
                            $hasText = is_string($text) && trim($text) !== '';
                            $hasImage = isset($imageFiles[$index]);
                            if (!$hasText && !$hasImage) {
                                $fail("Each option must have text or image.");
                            }
                        }
                    }
                ];
                $rules['option_images'] = 'nullable|array';
                $rules['option_images.*'] = 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096';
                $rules['correct_answer'] = 'required';
                break;

            case 'true_false':
                $rules['options'] = [
                    'required',
                    function ($attribute, $value, $fail) use ($request) {
                        $data = is_string($value) ? json_decode($value, true) : $value;
                        if (!is_array($data) || count($data) !== 2) {
                            return $fail('The options field must contain 2 items.');
                        }
                        foreach ($data as $opt) {
                            if (!is_string($opt) && !is_bool($opt)) {
                                return $fail('Each option must be a string or boolean representing True/False.');
                            }
                            $val = is_bool($opt) ? ($opt ? 'true' : 'false') : strtolower(trim($opt));
                            if (!in_array($val, ['true', 'false'], true)) {
                                return $fail('Each option must be "True" or "False".');
                            }
                        }
                    }
                ];

                $rules['correct_answer'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        if (is_bool($value)) {
                            return;
                        }
                        if (!is_string($value)) {
                            return $fail('The correct_answer must be True or False.');
                        }
                        $v = strtolower(trim($value));
                        if (!in_array($v, ['true', 'false'], true)) {
                            return $fail('The correct_answer must be True or False.');
                        }
                    }
                ];
                break;

            case 'short_answer':
                $rules['options'] = 'nullable';
                $rules['correct_answer'] = 'nullable|string';
                break;

            case 'matching':
                $rules['options'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        $data = is_string($value) ? json_decode($value, true) : $value;
                        if (!isset($data['left']) || !isset($data['right'])) {
                            $fail('Options must contain left and right arrays.');
                        }
                    }
                ];
                $rules['correct_answer'] = [
                    'required',
                    function ($attribute, $value, $fail) {
                        $pairs = is_string($value) ? json_decode($value, true) : $value;
                        foreach ($pairs as $pair) {
                            if (!isset($pair['left_index']) || !isset($pair['right_index'])) {
                                $fail('Each pair must have left_index & right_index.');
                            }
                        }
                    }
                ];
                break;
        }

        return $request->validate($rules);
    }
    private function validateQuestionData(Request $request)
    {
        $type = $request->question_type;

        $rules = [
            'topic_id' => 'required|exists:topics,id',
            'question' => 'required|string',
            'question_type' => 'required|in:mcq,true_false,short_answer,matching,parent',
            'marks' => 'nullable|numeric|min:0',
            'difficulty_level' => 'required|in:remembering,understanding,applying,analyzing,evaluating,creating',
            'is_math' => 'required|boolean',
            'is_chemistry' => 'required|boolean',
            'multiple_answers' => 'required|boolean',
            'is_required' => 'required|boolean',
            'explanation' => 'nullable|string',

            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
            'correct_answer_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',

            'parent_question_id' => 'nullable|exists:questions,id',

            // ✅ DEFAULT: correct_answer is ARRAY
            'correct_answer' => 'nullable|array',
            'correct_answer.*' => 'nullable|string',

            // options default
            'options' => 'nullable|array',
            'options.*' => 'nullable|string',
        ];

        // ------------------------------
        // Parent container
        // ------------------------------
        if ($type === 'parent') {
            $rules['options'] = 'nullable';
            $rules['correct_answer'] = 'nullable';
            return $request->validate($rules);
        }

        // ------------------------------
        // MCQ
        // ------------------------------
        if ($type === 'mcq') {
            $rules['options'] = [
                'required',
                'array',
                'min:2',
                function ($attribute, $value, $fail) use ($request) {
                    $imageFiles = $request->file('option_images', []);
                    foreach ($value as $index => $text) {
                        $hasText = is_string($text) && trim($text) !== '';
                        $hasImage = isset($imageFiles[$index]);
                        if (!$hasText && !$hasImage) {
                            $fail('Each option must have text or image.');
                        }
                    }
                }
            ];

            $rules['option_images'] = 'nullable|array';
            $rules['option_images.*'] = 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096';

            if ($request->boolean('multiple_answers')) {
                $rules['correct_answer'] = 'required|array|min:1';
            } else {
                $rules['correct_answer'] = 'required|array|size:1';
            }
        }

        // ------------------------------
        // TRUE / FALSE
        // ------------------------------
        if ($type === 'true_false') {
            $rules['options'] = 'required|array|size:2';

            $rules['correct_answer'] = [
                'required',
                'array',
                'size:1',
                function ($attribute, $value, $fail) {
                    $v = strtolower(trim((string)($value[0] ?? '')));
                    if (!in_array($v, ['true', 'false'], true)) {
                        $fail('The correct answer must be True or False.');
                    }
                }
            ];
        }

        // ------------------------------
        // SHORT ANSWER (OPTIONAL!)
        // ------------------------------
        if ($type === 'short_answer') {
            $rules['options'] = 'nullable';
            $rules['correct_answer'] = 'nullable|array';
            // image optional too → OK
        }

        // ------------------------------
        // MATCHING (JSON STRING)
        // ------------------------------
        if ($type === 'matching') {
            $rules['options'] = [
                'required',
                function ($attribute, $value, $fail) {
                    $data = is_string($value) ? json_decode($value, true) : $value;
                    if (!isset($data['left'], $data['right'])) {
                        $fail('Options must contain left and right arrays.');
                    }
                }
            ];

            // matching correct_answer stays JSON
            $rules['correct_answer'] = [
                'required',
                function ($attribute, $value, $fail) {
                    $pairs = is_string($value) ? json_decode($value, true) : $value;
                    if (!is_array($pairs) || empty($pairs)) {
                        $fail('Matching must have at least one pair.');
                    }
                    foreach ($pairs as $pair) {
                        if (!isset($pair['left_index'], $pair['right_index'])) {
                            $fail('Each pair must have left_index and right_index.');
                        }
                    }
                }
            ];
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
            ->whereNull('parent_question_id')
            ->with(['topic', 'subQuestions']) // Eager load relationships and sub-questions
            ->paginate($pageSize);

        // Normalize options/correct_answer for each parent question and its sub-questions
        $normalizedItems = $questions->getCollection()->map(function ($question) {
            $parent = $this->normalizeQuestionPayload($question);

            $parent->sub_questions = $question->subQuestions
                ->map(function ($sub) {
                    return $this->normalizeQuestionPayload($sub);
                })
                ->values();

            // If this question has sub-questions, treat it as a container only in the API
            if ($parent->sub_questions->count() > 0) {
                $parent->question_type = null;
            }

            return $parent;
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
            ->whereNull('parent_question_id')
            ->with(['topic', 'subQuestions']) // Eager load relationships and sub-questions
            ->get();

        // Normalize options/correct_answer for all parent questions and their sub-questions
        $normalized = $questions->map(function ($question) {
            $parent = $this->normalizeQuestionPayload($question);

            $parent->sub_questions = $question->subQuestions
                ->map(function ($sub) {
                    return $this->normalizeQuestionPayload($sub);
                })
                ->values();

            // If this question has sub-questions, treat it as a container only in the API
            if ($parent->sub_questions->count() > 0) {
                $parent->question_type = null;
            }

            return $parent;
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

        $question = Question::findOrFail($id);

        // Detect if this update payload includes sub-questions
        $subQuestionsInput = $request->input('sub_questions');
        $hasSub = is_array($subQuestionsInput) && count($subQuestionsInput) > 0;

        // If parent container, force semantics (no own options/correct_answer/marks)
        if ($hasSub) {
            $request->merge([
                'question_type' => 'parent',
                'options' => null,
                'correct_answer' => null,
                'marks' => null,
            ]);
        }

        // Validate
        $validated = $this->validateQuestionData($request);

        // Normalize question text if provided
        if (isset($validated['question']) && is_string($validated['question'])) {
            $validated['question'] = trim($validated['question']);
        }

        // ----------------------------------------------------
        // MCQ normalization (ONLY if not parent)
        // options is stored as JSON and casted to array in model
        // ----------------------------------------------------
        if (! $hasSub && (($validated['question_type'] ?? $question->question_type) === 'mcq')) {

            $textOptions = $validated['options'] ?? [];
            $imageFiles  = $request->file('option_images', []);

            // Because of casts, this is already an array (or null)
            $currentOptions = $question->options ?? [];

            $normalizedOptions = [];

            foreach ($textOptions as $index => $text) {
                $text = is_string($text) ? trim($text) : '';

                $imagePath = $currentOptions[$index]['image'] ?? null;

                if (isset($imageFiles[$index]) && $imageFiles[$index]) {
                    // Delete old image if exists
                    if ($imagePath) {
                        Storage::disk('public')->delete($imagePath);
                    }
                    $imagePath = $imageFiles[$index]->store('options', 'public');
                }

                $normalizedOptions[] = [
                    'text'  => $text !== '' ? $text : null,
                    'image' => $imagePath,
                ];
            }

            $validated['options'] = $normalizedOptions; // keep as array (casts will serialize)
        }

        // ----------------------------------------------------
        // Auto-assign marks (non-parent)
        // ----------------------------------------------------
        if (! $hasSub) {
            $effectiveType = $validated['question_type'] ?? $question->question_type;

            if (! in_array($effectiveType, ['matching', 'short_answer', 'parent'], true)) {
                if (
                    (! isset($validated['marks']) || (float)$validated['marks'] === 0.0)
                    && isset($validated['difficulty_level'])
                ) {
                    $validated['marks'] = match ($validated['difficulty_level']) {
                        'remembering', 'understanding' => 1,
                        'analyzing' => 2,
                        'applying', 'evaluating' => 3,
                        'creating' => 4,
                        default => 1,
                    };
                }
            }
        } else {
            // Parent container: no own marks/options/correct_answer
            $validated['marks'] = null;
            $validated['options'] = null;
            $validated['correct_answer'] = null;
        }

        // ----------------------------------------------------
        // Handle question image upload
        // ----------------------------------------------------
        if ($request->hasFile('question_image')) {
            if ($question->question_image) {
                Storage::disk('public')->delete($question->question_image);
            }
            $validated['question_image'] = $request->file('question_image')->store('questions', 'public');
        }

        // ----------------------------------------------------
        // Handle correct answer image upload for short_answer
        // ----------------------------------------------------
        if (! $hasSub) {
            $effectiveTypeForImage = $validated['question_type'] ?? $question->question_type;

            if ($effectiveTypeForImage === 'short_answer' && $request->hasFile('correct_answer_image')) {
                if ($question->correct_answer_image) {
                    Storage::disk('public')->delete($question->correct_answer_image);
                }
                $validated['correct_answer_image'] = $request->file('correct_answer_image')->store('answers', 'public');
            }
        }

        // ----------------------------------------------------
        // Normalize matching payloads (KEEP AS ARRAYS - casts will serialize)
        // ----------------------------------------------------
        if (! $hasSub && (($validated['question_type'] ?? $question->question_type) === 'matching')) {

            if (isset($validated['options']) && is_string($validated['options'])) {
                $validated['options'] = json_decode($validated['options'], true);
            }

            if (isset($validated['correct_answer']) && is_string($validated['correct_answer'])) {
                $validated['correct_answer'] = json_decode($validated['correct_answer'], true);
            }

            // Ensure arrays (avoid null)
            $validated['options'] = is_array($validated['options'] ?? null) ? $validated['options'] : [];
            $validated['correct_answer'] = is_array($validated['correct_answer'] ?? null) ? $validated['correct_answer'] : [];
        } elseif (! $hasSub) {
            // For all other non-parent types:
            // If options/correct_answer arrive as JSON strings, decode them.
            if (isset($validated['options']) && is_string($validated['options'])) {
                $decoded = json_decode($validated['options'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $validated['options'] = $decoded;
                }
            }

            if (isset($validated['correct_answer']) && is_string($validated['correct_answer'])) {
                $decoded = json_decode($validated['correct_answer'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $validated['correct_answer'] = $decoded;
                }
            }
        }

        DB::beginTransaction();

        try {
            // Update parent question
            $question->update($validated);

            // ----------------------------------------------------
            // Upsert sub-questions if provided
            // ----------------------------------------------------
            if ($hasSub) {
                $subQuestionsPayload = $request->input('sub_questions', []);

                $baseForSub = [
                    'topic_id' => $validated['topic_id'] ?? $question->topic_id,
                    'difficulty_level' => $validated['difficulty_level'] ?? $question->difficulty_level,
                    'is_math' => $validated['is_math'] ?? $question->is_math,
                    'is_chemistry' => $validated['is_chemistry'] ?? $question->is_chemistry,
                    'multiple_answers' => $validated['multiple_answers'] ?? $question->multiple_answers,
                    'is_required' => $validated['is_required'] ?? $question->is_required,
                    'parent_question_id' => $question->id,
                ];

                foreach ($subQuestionsPayload as $subData) {
                    if (! is_array($subData)) {
                        continue;
                    }

                    $subId = $subData['id'] ?? null;
                    unset($subData['id']);

                    $subRequest = new Request(array_merge($baseForSub, $subData));
                    $subValidated = $this->validateQuestionData($subRequest);

                    // Normalize matching arrays for sub questions too
                    $subType = $subValidated['question_type'] ?? null;

                    if ($subType === 'matching') {
                        if (isset($subValidated['options']) && is_string($subValidated['options'])) {
                            $subValidated['options'] = json_decode($subValidated['options'], true);
                        }
                        if (isset($subValidated['correct_answer']) && is_string($subValidated['correct_answer'])) {
                            $subValidated['correct_answer'] = json_decode($subValidated['correct_answer'], true);
                        }

                        $subValidated['options'] = is_array($subValidated['options'] ?? null) ? $subValidated['options'] : [];
                        $subValidated['correct_answer'] = is_array($subValidated['correct_answer'] ?? null) ? $subValidated['correct_answer'] : [];
                    } else {
                        if (isset($subValidated['options']) && is_string($subValidated['options'])) {
                            $decoded = json_decode($subValidated['options'], true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $subValidated['options'] = $decoded;
                            }
                        }
                        if (isset($subValidated['correct_answer']) && is_string($subValidated['correct_answer'])) {
                            $decoded = json_decode($subValidated['correct_answer'], true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $subValidated['correct_answer'] = $decoded;
                            }
                        }
                    }

                    // Auto-assign marks for non-matching and non-short_answer
                    if (! in_array($subType, ['matching', 'short_answer'], true)) {
                        if (! isset($subValidated['marks']) || (float)$subValidated['marks'] === 0.0) {
                            $subValidated['marks'] = match ($subValidated['difficulty_level']) {
                                'remembering', 'understanding' => 1,
                                'analyzing' => 2,
                                'applying', 'evaluating' => 3,
                                'creating' => 4,
                                default => 1,
                            };
                        }
                    }

                    $subValidated['created_by'] = $question->created_by;

                    if ($subId) {
                        $existingSub = Question::where('parent_question_id', $question->id)
                            ->where('id', $subId)
                            ->first();

                        if ($existingSub) {
                            $existingSub->update($subValidated);
                            continue;
                        }
                    }

                    Question::create($subValidated);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Question update failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // Reload for response (casts already return arrays)
        $question->refresh();
        $question->question_image_url = $question->question_image ? asset('storage/' . $question->question_image) : null;
        $question->correct_answer_image_url = $question->correct_answer_image ? asset('storage/' . $question->correct_answer_image) : null;

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

        // Only load parent questions created by the user, with their sub-questions
        $questions = Question::with([
            'topic.gradeSubject.gradeLevel',
            'topic.gradeSubject.subject',
            'subQuestions'
        ])
            ->where('created_by', $userId)
            ->whereNull('parent_question_id')
            ->select(
                'id',
                'topic_id',
                'question',
                'options',
                'question_type',
                'difficulty_level',
                'marks',
                'correct_answer',
                'question_image',
                'correct_answer_image'
            )
            ->paginate(10);

        // Normalize parent questions and include nested sub-questions
        $normalizedCollection = $questions->getCollection()->map(function ($question) {
            $parent = $this->normalizeQuestionPayload($question);

            $parent->sub_questions = $question->subQuestions
                ->map(function ($sub) {
                    return $this->normalizeQuestionPayload($sub);
                })
                ->values();

            // If this question has sub-questions, treat it as a container only in the API
            if ($parent->sub_questions->count() > 0) {
                $parent->question_type = null;
            }

            return $parent;
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
                    'difficulty_level',
                    'question_image',
                    'correct_answer_image'
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

        $query = Question::with(['topic.gradeSubject.gradeLevel', 'topic.gradeSubject.subject', 'subQuestions'])
            ->whereNull('parent_question_id');

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

        // Normalize parent questions and include nested sub-questions
        $normalizedItems = $questions->getCollection()->map(function ($question) {
            $parent = $this->normalizeQuestionPayload($question);

            $parent->sub_questions = $question->subQuestions
                ->map(function ($sub) {
                    return $this->normalizeQuestionPayload($sub);
                })
                ->values();

            // If this question has sub-questions, treat it as a container only in the API
            if ($parent->sub_questions->count() > 0) {
                $parent->question_type = null;
            }

            return $parent;
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
        if ($question->correct_answer_image) {
            Storage::disk('public')->delete($question->correct_answer_image);
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
            ($trimmed[0] === '{' && substr($trimmed, -1) === '}')
        ) {
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

        if (!empty($question->correct_answer_image)) {
            $question->correct_answer_image_url = asset('storage/' . $question->correct_answer_image);
        } else {
            $question->correct_answer_image_url = null;
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
                        if (isset($q['options']) && is_string($q['options'])) {
                            $q['options'] = json_decode($q['options'], true);
                        }

                        // Always store as array
                        if (!isset($q['correct_answer']) || $q['correct_answer'] === '' || $q['correct_answer'] === null) {
                            $q['correct_answer'] = [0]; // default first option
                        } elseif (!is_array($q['correct_answer'])) {
                            $q['correct_answer'] = [$q['correct_answer']];
                        }
                    } elseif ($type === 'true_false') {
                        $q['options'] = ['True', 'False'];

                        if (!isset($q['correct_answer']) || $q['correct_answer'] === '' || $q['correct_answer'] === null) {
                            $q['correct_answer'] = ['True'];
                        } elseif (!is_array($q['correct_answer'])) {
                            $q['correct_answer'] = [$q['correct_answer']];
                        }
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
        // Support bulk storing: accept either a single question payload
        // or an array of questions under the `questions` key.
        $incomingList = $request->input('questions');

        $toProcess = [];
        if (is_array($incomingList) && count($incomingList) > 0) {
            $toProcess = $incomingList;
        } else {
            $toProcess = [$request->all()];
        }

        $created = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($toProcess as $index => $item) {
                // Allow per-item overrides but fall back to top-level topic_id if missing
                if (!isset($item['topic_id']) && $request->has('topic_id')) {
                    $item['topic_id'] = $request->input('topic_id');
                }

                // Ensure question_type exists for validation
                $itemType = $item['question_type'] ?? $request->input('question_type');

                // Coerce correct_answer for MCQ / True-False into array shape
                if (in_array($itemType, ['mcq', 'true_false'], true)) {
                    if (! isset($item['correct_answer']) || $item['correct_answer'] === null || $item['correct_answer'] === '') {
                        $item['correct_answer'] = [];
                    } elseif (! is_array($item['correct_answer'])) {
                        $item['correct_answer'] = [$item['correct_answer']];
                    }
                }

                // Create a temporary Request for validation
                $subRequest = new Request($item);

                try {
                    $validated = $this->validateQuestionData($subRequest);
                } catch (\Illuminate\Validation\ValidationException $ve) {
                    $errors[$index] = $ve->errors();
                    continue;
                }

                // Trim question and normalize math/options similar to single flow
                $validated['question'] = trim($validated['question']);

                $hasMathInQuestion = isset($validated['question']) && $this->containsLatexMath($validated['question']);
                $hasMathInOptions = isset($validated['options']) && is_array($validated['options']) && $this->optionsContainLatexMath($validated['options']);

                if ($hasMathInQuestion) {
                    $validated['question'] = $this->normalizeMathQuestion($validated['question']);
                }
                if ($hasMathInOptions && isset($validated['options']) && is_array($validated['options'])) {
                    $validated['options'] = $this->normalizeMathOptions($validated['options']);
                }
                $validated['is_math'] = ($hasMathInQuestion || $hasMathInOptions) ? true : false;

                // Normalize MCQ options into {text,image} objects
                if (($validated['question_type'] ?? null) === 'mcq') {
                    $textOptions = $validated['options'] ?? [];
                    $normalizedOptions = [];
                    foreach ($textOptions as $t) {
                        $t = is_string($t) ? trim($t) : '';
                        $normalizedOptions[] = ['text' => $t !== '' ? $t : null, 'image' => null];
                    }
                    $validated['options'] = $normalizedOptions;
                }

                // Auto-assign marks for non-matching/short_answer
                if (!in_array($validated['question_type'], ['matching', 'short_answer'], true)) {
                    if (!isset($validated['marks']) || (float)$validated['marks'] === 0.0) {
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

                $question = Question::create($validated);
                $question->question_image_url = $question->question_image ? asset('storage/' . $question->question_image) : null;
                $created[] = $question;
            }

            if (!empty($errors)) {
                // Partial or full validation failure — roll back and return errors
                DB::rollBack();
                return response()->json(['success' => false, 'errors' => $errors], 422);
            }

            DB::commit();
            return response()->json(['success' => true, 'data' => $created], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AI bulk question save failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to save questions', 'details' => $e->getMessage()], 500);
        }
    }
}
