<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Question;
use App\Models\GradeLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            // Check for duplicate options if MCQ
            if ($validated['question_type'] === 'mcq') {
                    // Only trim options, no duplicate or similarity checks
                    $validated['options'] = array_map('trim', $validated['options']);
            }

            // Handle image upload
            if ($request->hasFile('question_image')) {
                $validated['question_image'] = $this->handleQuestionImage($request);
            }

            // Set marks based on difficulty level if not explicitly provided
            if (!isset($validated['marks']) || $validated['marks'] === 0) {
                $validated['marks'] = match($validated['difficulty_level']) {
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
        $rules = [
            'topic_id' => 'required|exists:topics,id',
            'question' => 'required|string',
            'question_type' => 'required|in:mcq,true_false,short_answer,matching',
            'options' => 'required|array|min:2',
            'correct_answer' => 'required|string',
            'marks' => 'required|numeric|min:0',
            'difficulty_level' => 'required|in:remembering,understanding,applying,analyzing,evaluating,creating',
            'is_math' => 'required|boolean',
            'is_chemistry' => 'required|boolean',
            'multiple_answers' => 'required|boolean',
            'is_required' => 'required|boolean',
            'explanation' => 'nullable|string',
            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
        ];
        // Additional rules based on question_type

        if ($request->question_type === 'mcq') {
            $rules['options'] = 'required|array|min:2';
            $rules['correct_answer'] = 'required|string';
        }

        if ($request->question_type === 'true_false') {
            $rules['correct_answer'] = 'required|in:true,false,1,0';
        }
        if ($request->question_type === 'short_answer') {
            $rules['correct_answer'] = 'nullable|string';
        }

        if ($request->question_type === 'matching') {
            $rules['correct_answer'] = [
                'required',
                function ($attribute, $value, $fail) {
                    $decoded = json_decode($value, true);

                    if (!is_array($decoded)) {
                        $fail('The correct_answer must be a valid JSON array.');
                        return;
                    }

                    foreach ($decoded as $pair) {
                        if (!isset($pair['left']) || !isset($pair['right'])) {
                            $fail('Each matching pair must have left and right values.');
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
        $questions = Question::where('topic_id', $topicId)
            ->with('topic') // Eager load relationships
            ->get();

        return response()->json([
            'success' => true,
            'data' => $questions,
        ]);
    }

    public function update(Request $request, $id)
    {
        Log::info('Updating question with ID: ' . $id);

        try {
            $question = Question::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        $validated = $request->validate([
            'topic_id' => 'sometimes|required|exists:topics,id',
            'question' => 'sometimes|required|string',
            'question_type' => 'sometimes|required|in:mcq,true_false,short_answer',
            'options' => 'sometimes|required|array|min:2',
            'correct_answer' => 'sometimes|required|string',
            'marks' => 'sometimes|required|numeric|min:0',
            'difficulty_level' => 'sometimes|required|in:remembering,understanding,applying,analyzing,evaluating,creating',
            'is_math' => 'sometimes|required|boolean',
            'is_chemistry' => 'sometimes|required|boolean',
            'multiple_answers' => 'sometimes|required|boolean',
            'is_required' => 'sometimes|required|boolean',
            'explanation' => 'nullable|string',
            'question_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:4096',
        ]);

        // Handle image upload
        if ($request->hasFile('question_image')) {
            if ($question->question_image) {
                Storage::disk('public')->delete($question->question_image);
            }
            $image = $request->file('question_image');
            $path = $image->store('questions', 'public');
            $validated['question_image'] = $path;
        }

        // Process question content for math formatting if needed
        // We'll store KaTeX content in the options JSON for now
        // until we can run the migration

        // Update options if provided
        if ($request->has('options') && $request->question_type === 'mcq') {
            $options = [];
            foreach ($request->options as $opt) {
                $optionText = is_array($opt) ? implode(' ', $opt) : $opt;

                $optionData = [
                    'text' => $optionText,
                    'image' => null,
                    'katex_content' => null
                ];

                if ($request->is_math) {
                    $optionData['katex_content'] = $optionText;
                    $optionData['image'] = $this->renderLatexToImage($optionText);
                } elseif ($request->is_chemistry) {
                    $optionData['katex_content'] = '\\ce{' . $optionText . '}';
                    $optionData['image'] = $this->renderLatexToImage('\\ce{' . $optionText . '}');
                }

                $options[] = $optionData;
            }
            $question->options = $options;

            // Store the question's KaTeX content in the metadata field
            if ($request->is_math || $request->is_chemistry) {
                // Create a metadata array to store additional information
                $metadata = [
                    'katex_content' => $request->question,
                    'is_math' => $request->is_math,
                    'is_chemistry' => $request->is_chemistry
                ];
                $question->metadata = $metadata;
            }
        } elseif (isset($validated['options'])) {
            $validated['options'] = json_encode($validated['options']);
        }

        $question->update($validated);

        // Reload and transform the question for response
        $question = Question::findOrFail($id);
        $question->options = json_decode($question->options);
        //$question->correct_answer = json_decode($question->correct_answer);
        if ($question->question_image) {
            $question->question_image_url = asset('storage/' . $question->question_image);
        } else {
            $question->question_image_url = null;
        }

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
}
