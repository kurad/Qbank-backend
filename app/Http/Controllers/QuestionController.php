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

            // Check for similar questions using Levenshtein distance (fuzzy matching)
            foreach ($existingQuestions as $existing) {
                $distance = levenshtein(
                    strtolower($existing),
                    strtolower($questionText)
                );
                
                // If the strings are very similar (length difference <= 20% of the longer string)
                $maxLength = max(mb_strlen($existing), mb_strlen($questionText));
                $similarityThreshold = $maxLength * 0.8; // 80% similarity
                
                if ($distance > 0 && $distance <= $similarityThreshold) {
                    return response()->json([
                        'error' => 'A similar question already exists in this topic.',
                        'existing_question' => $existing,
                        'new_question' => $questionText,
                        'similarity' => 'high',
                        'suggestion' => 'Please review if this is a duplicate or rephrase your question.'
                    ], 422);
                }
            }

            // Check for duplicate options if MCQ
            if ($validated['question_type'] === 'mcq') {
                $options = array_map('trim', $validated['options']);
                $options = array_map('strtolower', $options);
                
                // Check for duplicate options (case-insensitive)
                $uniqueOptions = array_unique($options);
                if (count($options) !== count($uniqueOptions)) {
                    $duplicates = array_diff_assoc($options, array_unique($options));
                    $duplicateValues = array_unique(array_values($duplicates));
                    
                    return response()->json([
                        'error' => 'Duplicate options detected in the question.',
                        'duplicate_options' => $duplicateValues,
                        'suggestion' => 'Please ensure all options are unique.'
                    ], 422);
                }
                // Check for similar options using Levenshtein distance
                foreach ($options as $i => $option1) {
                    foreach (array_slice($options, $i + 1) as $option2) {
                        $distance = levenshtein($option1, $option2);
                        $maxLength = max(strlen($option1), strlen($option2));
                        $similarityThreshold = $maxLength * 0.7; // 70% similarity threshold for options
                        
                        if ($distance > 0 && $distance <= $similarityThreshold) {
                            return response()->json([
                                'error' => 'Similar options detected that might be duplicates.',
                                'option1' => $validated['options'][$i],
                                'option2' => $validated['options'][array_search($option2, $options)],
                                'suggestion' => 'Please ensure all options are distinct.'
                            ], 422);
                        }
                    }
                }
                
                $validated['options'] = array_map('trim', $validated['options']);
            }

            // Handle image upload
            if ($request->hasFile('question_image')) {
                $validated['question_image'] = $this->handleQuestionImage($request);
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
