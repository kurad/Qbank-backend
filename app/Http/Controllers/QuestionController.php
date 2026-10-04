<?php

namespace App\Http\Controllers;

use App\Models\GradeSubject;
use App\Models\LearningObjective;
use App\Models\Question;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Services\AI\AIGateway;

class QuestionController extends Controller
{
    public function __construct(protected AIGateway $ai)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE TEACHER QUESTION
    |--------------------------------------------------------------------------
    */

    public function store(Request $request)
    {
        try {
            $user = auth()->user();

            Log::info('Creating question', [
                'user_id' => $user->id,
            ]);

            $subQuestionsInput = $request->input('sub_questions');
            $hasSub = is_array($subQuestionsInput) && count($subQuestionsInput) > 0;

            /*
             * A parent question is only a container when it has
             * sub-questions.
             */
            if ($hasSub) {
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

            $validated = $this->validateQuestionData($request);

            $topic = Topic::with('gradeSubject')->findOrFail($validated['topic_id']);

            $this->ownsTopic($topic);

            $this->validateLearningObjectiveBelongsToTopic(
                $validated['learning_objective_id'] ?? null,
                $topic
            );

            if (isset($validated['question'])) {
                $validated['question'] = trim($validated['question']);
            }

            /*
             * Prevent duplicate teacher questions in the same topic.
             */
            if (!$hasSub) {
                $this->rejectDuplicateQuestion(
                    $topic->id,
                    $validated['learning_objective_id'] ?? null,
                    $validated['question_type'] ?? null,
                    $validated['question']
                );
            }

            /*
             * Normalize MCQ options.
             */
            if (
                !$hasSub &&
                ($validated['question_type'] ?? null) === 'mcq'
            ) {
                $validated['options'] = $this->normalizeMcqOptions(
                    $validated['options'] ?? [],
                    $request->file('option_images', [])
                );
            }

            /*
             * Normalize matching questions.
             */
            if (
                !$hasSub &&
                ($validated['question_type'] ?? null) === 'matching'
            ) {
                $validated['options'] = $this->decodeArray(
                    $validated['options'] ?? []
                );

                $validated['correct_answer'] = $this->decodeArray(
                    $validated['correct_answer'] ?? []
                );
            }

            /*
             * MCQ / True-False answers are stored as arrays.
             */
            if (
                !$hasSub &&
                in_array(
                    $validated['question_type'] ?? null,
                    ['mcq', 'true_false'],
                    true
                )
            ) {
                if (
                    array_key_exists('correct_answer', $validated) &&
                    !is_array($validated['correct_answer'])
                ) {
                    $validated['correct_answer'] = [
                        $validated['correct_answer']
                    ];
                }
            }

            /*
             * Parent containers do not have their own answer/options/marks.
             */
            if ($hasSub) {
                $validated['options'] = null;
                $validated['correct_answer'] = null;
                $validated['marks'] = null;
            }

            /*
             * Question image.
             */
            if ($request->hasFile('question_image')) {
                $validated['question_image'] =
                    $this->handleQuestionImage($request);
            }

            /*
             * Short answer answer image.
             */
            if (
                !$hasSub &&
                ($validated['question_type'] ?? null) === 'short_answer' &&
                $request->hasFile('correct_answer_image')
            ) {
                $validated['correct_answer_image'] =
                    $request->file('correct_answer_image')
                    ->store('answers', 'public');
            }

            /*
             * Automatic marks.
             */
            if (
                !$hasSub &&
                !in_array(
                    $validated['question_type'] ?? null,
                    ['matching', 'short_answer'],
                    true
                )
            ) {
                $validated['marks'] = $this->autoMarks(
                    $validated['marks'] ?? null,
                    $validated['difficulty_level']
                );
            }

            /*
             * Teacher-created questions are immediately approved.
             */
            $validated['created_by'] = $user->id;
            $validated['source'] = 'teacher';
            $validated['status'] = 'approved';
            $validated['is_assessment_eligible'] = true;

            DB::beginTransaction();

            try {
                $question = Question::create($validated);

                /*
                 * Create subquestions.
                 */
                if ($hasSub) {
                    $this->createSubQuestions(
                        $question,
                        $request->input('sub_questions', []),
                        $validated
                    );
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('Question creation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $question->refresh();

            return response()->json(
                $this->normalizeQuestionPayload(
                    $question->load([
                        'topic.gradeSubject.subject',
                        'topic.gradeSubject.gradeLevel',
                        'topic.unit',
                        'learningObjective',
                        'subQuestions.learningObjective',
                    ])
                ),
                201
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('Question creation error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create question',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    private function validateQuestionData(Request $request): array
    {
        $type = $request->input('question_type');

        $rules = [
            'topic_id' => [
                'required',
                'integer',
                'exists:topics,id',
            ],

            'learning_objective_id' => [
                'nullable',
                'integer',
                Rule::exists('learning_objectives', 'id')
                    ->where(function ($query) use ($request) {
                        $query->where(
                            'topic_id',
                            $request->input('topic_id')
                        );
                    }),
            ],

            'question' => [
                'required',
                'string',
            ],

            'question_type' => [
                'required',
                'in:mcq,true_false,short_answer,matching,parent',
            ],

            'marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'difficulty_level' => [
                'required',
                'in:remembering,understanding,applying,analyzing,evaluating,creating',
            ],

            'is_math' => [
                'required',
                'boolean',
            ],

            'is_chemistry' => [
                'required',
                'boolean',
            ],

            'multiple_answers' => [
                'required',
                'boolean',
            ],

            'is_required' => [
                'required',
                'boolean',
            ],

            'explanation' => [
                'nullable',
                'string',
            ],

            'question_image' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg,gif,svg',
                'max:4096',
            ],

            'correct_answer_image' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg,gif,svg',
                'max:4096',
            ],

            'parent_question_id' => [
                'nullable',
                'integer',
                'exists:questions,id',
            ],

            'correct_answer' => [
                'nullable',
                'array',
            ],

            'correct_answer.*' => [
                'nullable',
                'string',
            ],

            'options' => [
                'nullable',
                'array',
            ],

            'options.*' => [
                'nullable',
            ],
        ];

        /*
         * Parent container.
         */
        if ($type === 'parent') {
            $rules['options'] = 'nullable';
            $rules['correct_answer'] = 'nullable';

            return $request->validate($rules);
        }

        /*
         * MCQ.
         */
        if ($type === 'mcq') {
            $rules['options'] = [
                'required',
                'array',
                'min:2',
                function ($attribute, $value, $fail) use ($request) {
                    $imageFiles = $request->file('option_images', []);

                    foreach ($value as $index => $text) {
                        $hasText =
                            is_string($text) &&
                            trim($text) !== '';

                        $hasImage =
                            isset($imageFiles[$index]) &&
                            $imageFiles[$index];

                        if (!$hasText && !$hasImage) {
                            $fail(
                                'Each option must have text or image.'
                            );
                        }
                    }
                },
            ];

            $rules['option_images'] = [
                'nullable',
                'array',
            ];

            $rules['option_images.*'] = [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg,gif,svg',
                'max:4096',
            ];

            if ($request->boolean('multiple_answers')) {
                $rules['correct_answer'] = [
                    'required',
                    'array',
                    'min:1',
                ];
            } else {
                $rules['correct_answer'] = [
                    'required',
                    'array',
                    'size:1',
                ];
            }
        }

        /*
         * TRUE / FALSE.
         */
        if ($type === 'true_false') {
            $rules['options'] = [
                'required',
                'array',
                'size:2',
            ];

            $rules['correct_answer'] = [
                'required',
                'array',
                'size:1',
                function ($attribute, $value, $fail) {
                    $answer = strtolower(
                        trim((string)($value[0] ?? ''))
                    );

                    if (!in_array(
                        $answer,
                        ['true', 'false'],
                        true
                    )) {
                        $fail(
                            'The correct answer must be True or False.'
                        );
                    }
                },
            ];
        }

        /*
         * SHORT ANSWER.
         */
        if ($type === 'short_answer') {
            $rules['options'] = 'nullable';
            $rules['correct_answer'] = 'nullable|array';
        }

        /*
         * MATCHING.
         */
        if ($type === 'matching') {
            // Matching answers are structured objects, not strings.
            // Override the generic correct_answer.* string rule defined above.
            $rules['correct_answer.*'] = [
                'required',
                'array',
            ];

            $rules['correct_answer.*.left_index'] = [
                'required',
                'integer',
                'min:0',
            ];

            $rules['correct_answer.*.right_index'] = [
                'required',
                'integer',
                'min:0',
            ];

            $rules['options'] = [
                'required',
                function ($attribute, $value, $fail) {
                    $data = is_string($value)
                        ? json_decode($value, true)
                        : $value;

                    if (
                        !is_array($data) ||
                        !isset($data['left']) ||
                        !isset($data['right'])
                    ) {
                        $fail(
                            'Options must contain left and right arrays.'
                        );
                    }
                },
            ];

            $rules['correct_answer'] = [
                'required',
                function ($attribute, $value, $fail) {
                    $pairs = is_string($value)
                        ? json_decode($value, true)
                        : $value;

                    if (!is_array($pairs) || empty($pairs)) {
                        $fail(
                            'Matching must have at least one pair.'
                        );
                        return;
                    }

                    foreach ($pairs as $pair) {
                        if (
                            !is_array($pair) ||
                            !isset($pair['left_index']) ||
                            !isset($pair['right_index'])
                        ) {
                            $fail(
                                'Each pair must have left_index and right_index.'
                            );
                            return;
                        }
                    }
                },
            ];
        }

        return $request->validate($rules);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(Request $request, $id)
    {
        try {
            $question = Question::with([
                'topic.gradeSubject',
                'subQuestions',
            ])->findOrFail($id);

            $this->ownsQuestion($question);

            $subQuestionsInput = $request->input('sub_questions');
            $hasSub =
                is_array($subQuestionsInput) &&
                count($subQuestionsInput) > 0;

            if ($hasSub) {
                $request->merge([
                    'question_type' => 'parent',
                    'options' => null,
                    'correct_answer' => null,
                    'marks' => null,
                ]);
            }

            $validated = $this->validateQuestionData($request);

            /*
             * Prevent changing a question into another teacher's topic.
             */
            $topicId =
                $validated['topic_id'] ??
                $question->topic_id;

            $topic = Topic::with('gradeSubject')->findOrFail($topicId);

            $this->ownsTopic($topic);

            $this->validateLearningObjectiveBelongsToTopic(
                $validated['learning_objective_id'] ?? null,
                $topic
            );

            /*
             * Duplicate protection.
             */
            if (!$hasSub) {
                $this->rejectDuplicateQuestion(
                    $topic->id,
                    $validated['learning_objective_id'] ?? $question->learning_objective_id,
                    $validated['question_type'] ?? $question->question_type,
                    trim($validated['question']),
                    $question->id
                );
            }

            $validated['question'] =
                trim($validated['question']);

            /*
             * MCQ.
             */
            if (
                !$hasSub &&
                ($validated['question_type'] ?? null) === 'mcq'
            ) {
                $validated['options'] =
                    $this->normalizeMcqOptionsForUpdate(
                        $validated['options'] ?? [],
                        $request->file('option_images', []),
                        $question->options ?? []
                    );
            }

            /*
             * Matching.
             */
            if (
                !$hasSub &&
                ($validated['question_type'] ?? null) === 'matching'
            ) {
                $validated['options'] =
                    $this->decodeArray(
                        $validated['options'] ?? []
                    );

                $validated['correct_answer'] =
                    $this->decodeArray(
                        $validated['correct_answer'] ?? []
                    );
            }

            /*
             * MCQ / True-False answer normalization.
             */
            if (
                !$hasSub &&
                in_array(
                    $validated['question_type'] ?? null,
                    ['mcq', 'true_false'],
                    true
                ) &&
                isset($validated['correct_answer']) &&
                !is_array($validated['correct_answer'])
            ) {
                $validated['correct_answer'] = [
                    $validated['correct_answer']
                ];
            }

            /*
             * Parent.
             */
            if ($hasSub) {
                $validated['marks'] = null;
                $validated['options'] = null;
                $validated['correct_answer'] = null;
            } else {
                $effectiveType =
                    $validated['question_type'] ??
                    $question->question_type;

                if (
                    !in_array(
                        $effectiveType,
                        ['matching', 'short_answer', 'parent'],
                        true
                    )
                ) {
                    $validated['marks'] =
                        $this->autoMarks(
                            $validated['marks'] ?? null,
                            $validated['difficulty_level'] ??
                                $question->difficulty_level
                        );
                }
            }

            /*
             * Question image.
             */
            if ($request->hasFile('question_image')) {
                if ($question->question_image) {
                    Storage::disk('public')->delete(
                        $question->question_image
                    );
                }

                $validated['question_image'] =
                    $request->file('question_image')
                    ->store('questions', 'public');
            }

            /*
             * Correct answer image.
             */
            if (
                !$hasSub &&
                ($validated['question_type'] ??
                    $question->question_type) === 'short_answer' &&
                $request->hasFile('correct_answer_image')
            ) {
                if ($question->correct_answer_image) {
                    Storage::disk('public')->delete(
                        $question->correct_answer_image
                    );
                }

                $validated['correct_answer_image'] =
                    $request->file('correct_answer_image')
                    ->store('answers', 'public');
            }

            /*
             * Preserve workflow metadata.
             *
             * Teacher questions remain approved.
             * AI questions remain draft until explicitly approved.
             */
            unset(
                $validated['created_by'],
                $validated['source'],
                $validated['status'],
                $validated['is_assessment_eligible']
            );

            DB::beginTransaction();

            try {
                $question->update($validated);

                /*
                 * Update/create subquestions.
                 */
                if ($hasSub) {
                    $this->updateSubQuestions(
                        $question,
                        $request->input('sub_questions', [])
                    );
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();

                Log::error('Question update failed', [
                    'question_id' => $question->id,
                    'user_id' => auth()->id(),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $question->refresh()->load([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ]);

            return response()->json(
                $this->normalizeQuestionPayload($question)
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('Question update error', [
                'question_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update question',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE SUBQUESTIONS
    |--------------------------------------------------------------------------
    */

    private function createSubQuestions(
        Question $parent,
        array $items,
        array $parentData
    ): void {
        foreach ($items as $subData) {
            if (!is_array($subData)) {
                continue;
            }

            $subData['topic_id'] = $parent->topic_id;

            $subData['learning_objective_id'] =
                $subData['learning_objective_id'] ??
                $parent->learning_objective_id;

            $subData['difficulty_level'] =
                $subData['difficulty_level'] ??
                $parent->difficulty_level;

            $subData['is_math'] =
                $subData['is_math'] ??
                $parent->is_math;

            $subData['is_chemistry'] =
                $subData['is_chemistry'] ??
                $parent->is_chemistry;

            $subData['multiple_answers'] =
                $subData['multiple_answers'] ?? false;

            $subData['is_required'] =
                $subData['is_required'] ?? true;

            $subData['parent_question_id'] =
                $parent->id;

            $subRequest = new Request($subData);

            $subValidated =
                $this->validateQuestionData($subRequest);

            $subValidated['topic_id'] =
                $parent->topic_id;

            $this->validateLearningObjectiveBelongsToTopic(
                $subValidated['learning_objective_id'] ?? null,
                $parent->topic
            );

            $subValidated['question'] =
                trim($subValidated['question']);

            /*
             * Normalize MCQ.
             */
            if (
                ($subValidated['question_type'] ?? null) === 'mcq'
            ) {
                $subValidated['options'] =
                    $this->normalizeMcqOptions(
                        $subValidated['options'] ?? [],
                        []
                    );
            }

            /*
             * Normalize matching.
             */
            if (
                ($subValidated['question_type'] ?? null) === 'matching'
            ) {
                $subValidated['options'] =
                    $this->decodeArray(
                        $subValidated['options'] ?? []
                    );

                $subValidated['correct_answer'] =
                    $this->decodeArray(
                        $subValidated['correct_answer'] ?? []
                    );
            }

            /*
             * Normalize MCQ / true false answers.
             */
            if (
                in_array(
                    $subValidated['question_type'] ?? null,
                    ['mcq', 'true_false'],
                    true
                ) &&
                isset($subValidated['correct_answer']) &&
                !is_array($subValidated['correct_answer'])
            ) {
                $subValidated['correct_answer'] = [
                    $subValidated['correct_answer']
                ];
            }

            /*
             * Marks.
             */
            if (
                !in_array(
                    $subValidated['question_type'] ?? null,
                    ['matching', 'short_answer'],
                    true
                )
            ) {
                $subValidated['marks'] =
                    $this->autoMarks(
                        $subValidated['marks'] ?? null,
                        $subValidated['difficulty_level']
                    );
            }

            /*
             * Parent workflow is inherited.
             */
            $subValidated['created_by'] =
                $parent->created_by;

            $subValidated['source'] =
                $parent->source;

            $subValidated['status'] =
                $parent->status;

            $subValidated['is_assessment_eligible'] =
                $parent->is_assessment_eligible;

            $subValidated['parent_question_id'] =
                $parent->id;

            Question::create($subValidated);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE SUBQUESTIONS
    |--------------------------------------------------------------------------
    */

    private function updateSubQuestions(
        Question $parent,
        array $items
    ): void {
        foreach ($items as $subData) {
            if (!is_array($subData)) {
                continue;
            }

            $subId = $subData['id'] ?? null;
            unset($subData['id']);

            $subData['topic_id'] =
                $parent->topic_id;

            $subData['learning_objective_id'] =
                $subData['learning_objective_id'] ??
                $parent->learning_objective_id;

            $subData['difficulty_level'] =
                $subData['difficulty_level'] ??
                $parent->difficulty_level;

            $subData['is_math'] =
                $subData['is_math'] ??
                $parent->is_math;

            $subData['is_chemistry'] =
                $subData['is_chemistry'] ??
                $parent->is_chemistry;

            $subData['multiple_answers'] =
                $subData['multiple_answers'] ?? false;

            $subData['is_required'] =
                $subData['is_required'] ?? true;

            $subRequest = new Request($subData);

            $subValidated =
                $this->validateQuestionData($subRequest);

            $subValidated['topic_id'] =
                $parent->topic_id;

            $this->validateLearningObjectiveBelongsToTopic(
                $subValidated['learning_objective_id'] ?? null,
                $parent->topic
            );

            $subValidated['question'] =
                trim($subValidated['question']);

            if (
                ($subValidated['question_type'] ?? null) === 'mcq'
            ) {
                $subValidated['options'] =
                    $this->normalizeMcqOptions(
                        $subValidated['options'] ?? [],
                        []
                    );
            }

            if (
                ($subValidated['question_type'] ?? null) === 'matching'
            ) {
                $subValidated['options'] =
                    $this->decodeArray(
                        $subValidated['options'] ?? []
                    );

                $subValidated['correct_answer'] =
                    $this->decodeArray(
                        $subValidated['correct_answer'] ?? []
                    );
            }

            if (
                in_array(
                    $subValidated['question_type'] ?? null,
                    ['mcq', 'true_false'],
                    true
                ) &&
                isset($subValidated['correct_answer']) &&
                !is_array($subValidated['correct_answer'])
            ) {
                $subValidated['correct_answer'] = [
                    $subValidated['correct_answer']
                ];
            }

            if (
                !in_array(
                    $subValidated['question_type'] ?? null,
                    ['matching', 'short_answer'],
                    true
                )
            ) {
                $subValidated['marks'] =
                    $this->autoMarks(
                        $subValidated['marks'] ?? null,
                        $subValidated['difficulty_level']
                    );
            }

            /*
             * Subquestions inherit workflow from parent.
             */
            $subValidated['created_by'] =
                $parent->created_by;

            $subValidated['source'] =
                $parent->source;

            $subValidated['status'] =
                $parent->status;

            $subValidated['is_assessment_eligible'] =
                $parent->is_assessment_eligible;

            $subValidated['parent_question_id'] =
                $parent->id;

            if ($subId) {
                $sub = Question::where('id', $subId)
                    ->where('parent_question_id', $parent->id)
                    ->first();

                if ($sub) {
                    $sub->update($subValidated);
                    continue;
                }
            }

            Question::create($subValidated);
        }
    }

    public function byTopic($topicId, Request $request)
    {
        $topic = Topic::with([
            'gradeSubject.subject',
            'gradeSubject.gradeLevel',
            'unit',
        ])->findOrFail($topicId);

        $this->ownsTopic($topic);

        $pageSize = min(
            max((int) $request->input('page_size', 10), 1),
            100
        );

        $questions = Question::where('topic_id', $topic->id)
            ->whereNull('parent_question_id')
            ->with([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ])
            ->orderByDesc('id')
            ->paginate($pageSize);

        $questions->getCollection()->transform(
            fn($question) =>
            $this->normalizeQuestionWithSubQuestions($question)
        );

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
        $topic = Topic::with([
            'gradeSubject.subject',
            'gradeSubject.gradeLevel',
            'unit',
        ])->findOrFail($topicId);

        $this->ownsTopic($topic);

        $questions = Question::where('topic_id', $topic->id)
            ->whereNull('parent_question_id')
            ->with([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ])
            ->orderBy('id')
            ->get();

        $normalized = $questions
            ->map(
                fn($question) =>
                $this->normalizeQuestionWithSubQuestions($question)
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => $normalized,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ALL QUESTIONS
    |--------------------------------------------------------------------------
    */

    public function allQuestions()
    {
        $user = auth()->user();

        $query = Question::query()
            ->with([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ])
            ->whereNull('parent_question_id');

        $this->scopeQuestionsToUser(
            $query,
            $user
        );

        $questions = $query
            ->orderByDesc('id')
            ->get();

        $normalized = $questions->map(
            fn($question) =>
            $this->normalizeQuestionWithSubQuestions($question)
        )->values();

        return response()->json($normalized);
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show($id)
    {
        try {
            $question = Question::with([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ])->findOrFail($id);

            $this->ownsQuestion($question);

            return response()->json(
                $this->normalizeQuestionWithSubQuestions(
                    $question
                )
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Question not found',
            ], 404);
        }
    }

    public function getQuestionCount($id)
    {
        $topic = Topic::with([
            'gradeSubject',
        ])->findOrFail($id);

        $this->ownsTopic($topic);

        return response()->json([
            'success' => true,
            'count' => Question::where('topic_id', $topic->id)
                ->whereNull('parent_question_id')
                ->count(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MY QUESTIONS
    |--------------------------------------------------------------------------
    */

    public function myQuestions(Request $request)
    {
        $user = auth()->user();

        $questions = Question::with([
            'topic.gradeSubject.gradeLevel',
            'topic.gradeSubject.subject',
            'topic.unit',
            'learningObjective',
            'subQuestions.learningObjective',
        ])
            ->where('created_by', $user->id)
            ->whereNull('parent_question_id')
            ->orderByDesc('id')
            ->paginate(
                min((int)$request->input('page_size', 10), 100)
            );

        $questions->getCollection()
            ->transform(function ($question) {
                return $this->normalizeQuestionWithSubQuestions(
                    $question
                );
            });

        return response()->json([
            'data' => $questions->items(),
            'pagination' => [
                'current_page' => $questions->currentPage(),
                'last_page' => $questions->lastPage(),
                'per_page' => $questions->perPage(),
                'total' => $questions->total(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TOPICS + QUESTIONS BY SUBJECT
    |--------------------------------------------------------------------------
    */

    public function topicsWithQuestionsBySubject($subjectId)
    {
        $user = auth()->user();

        $gradeSubjectQuery = GradeSubject::query()
            ->where('subject_id', $subjectId);

        if ($user->role === 'admin') {
            $gradeSubjectQuery->where(
                'school_id',
                $user->school_id
            );
        } elseif ($user->role === 'teacher') {
            $gradeSubjectQuery
                ->where('teacher_id', $user->id)
                ->where('school_id', $user->school_id);
        } else {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $gradeSubjectIds = $gradeSubjectQuery->pluck('id');

        $topics = Topic::whereIn('grade_subject_id', $gradeSubjectIds)
            ->with([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
                'questions' => function ($query) {
                    $query->whereNull('parent_question_id')
                        ->with([
                            'learningObjective',
                            'subQuestions.learningObjective',
                        ]);
                },
            ])
            ->orderBy('topic_name')
            ->get();

        $grouped = $topics->map(function ($topic) {
            return [
                'topic_id' => $topic->id,
                'topic_name' => $topic->topic_name,
                'grade_subject_id' => $topic->grade_subject_id,
                'unit_id' => $topic->unit_id,
                'questions' => $topic->questions
                    ->map(
                        fn($question) =>
                        $this->normalizeQuestionWithSubQuestions(
                            $question
                        )
                    )
                    ->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SEARCH
    |--------------------------------------------------------------------------
    */

    public function search(Request $request)
    {
        $user = auth()->user();

        $search = $request->input('search');
        $topicId = $request->input('topic_id');
        $learningObjectiveId =
            $request->input('learning_objective_id');
        $subjectId = $request->input('subject_id');
        $gradeLevelId =
            $request->input('grade_level_id');
        $questionType =
            $request->input('question_type');
        $difficulty =
            $request->input('difficulty_level');
        $createdBy =
            $request->input('created_by');

        $pageSize = min(
            (int)$request->input('page_size', 10),
            100
        );

        $query = Question::with([
            'topic.gradeSubject.gradeLevel',
            'topic.gradeSubject.subject',
            'topic.unit',
            'learningObjective',
            'subQuestions.learningObjective',
        ])
            ->whereNull('parent_question_id');

        /*
         * Always scope to the current school.
         */
        $this->scopeQuestionsToUser(
            $query,
            $user
        );

        if (!empty($search)) {
            $query->where(
                'question',
                'like',
                '%' . $search . '%'
            );
        }

        if (!empty($topicId)) {
            $query->where('topic_id', $topicId);
        }

        if (!empty($learningObjectiveId)) {
            $query->where(
                'learning_objective_id',
                $learningObjectiveId
            );
        }

        if (!empty($subjectId)) {
            $query->whereHas(
                'topic.gradeSubject',
                fn($q) =>
                $q->where('subject_id', $subjectId)
            );
        }

        if (!empty($gradeLevelId)) {
            $query->whereHas(
                'topic.gradeSubject',
                fn($q) =>
                $q->where(
                    'grade_level_id',
                    $gradeLevelId
                )
            );
        }

        if (!empty($questionType)) {
            $query->where(
                'question_type',
                $questionType
            );
        }

        if (!empty($difficulty)) {
            $query->where(
                'difficulty_level',
                $difficulty
            );
        }

        if (!empty($createdBy)) {
            $query->where(
                'created_by',
                $createdBy
            );
        }

        $questions = $query
            ->orderByDesc('id')
            ->paginate($pageSize);

        $questions->getCollection()
            ->transform(function ($question) {
                return $this->normalizeQuestionWithSubQuestions(
                    $question
                );
            });

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

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    public function destroy($id)
    {
        $question = Question::with([
            'topic.gradeSubject',
            'subQuestions',
        ])->findOrFail($id);

        $this->ownsQuestion($question);

        DB::beginTransaction();

        try {
            /*
             * Delete child images first.
             */
            foreach ($question->subQuestions as $sub) {
                $this->deleteQuestionImages($sub);
                $sub->delete();
            }

            $this->deleteQuestionImages($question);

            $question->delete();

            DB::commit();

            return response()->json([
                'message' => 'Question deleted successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Question deletion failed', [
                'question_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to delete question',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AI QUESTION GENERATION - PREVIEW ONLY
    |--------------------------------------------------------------------------
    */

    public function generateAIQuestions(Request $request)
    {
        $validated = $request->validate([
            'prompt' => [
                'required',
                'string',
                'max:2000',
            ],

            'topic_id' => [
                'required',
                'exists:topics,id',
            ],

            'learning_objective_id' => [
                'nullable',
                'integer',
                Rule::exists('learning_objectives', 'id')
                    ->where(function ($query) use ($request) {
                        $query->where(
                            'topic_id',
                            $request->input('topic_id')
                        );
                    }),
            ],

            'question_type' => [
                'required',
                'in:mcq,true_false,short_answer,matching',
            ],

            'difficulty_level' => [
                'required',
                'in:remembering,understanding,applying,analyzing,evaluating,creating',
            ],

            'count' => [
                'nullable',
                'integer',
                'min:1',
                'max:20',
            ],

            'marks' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'instruction' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        try {
            $topic = Topic::with([
                'gradeSubject.subject',
                'gradeSubject.gradeLevel',
                'unit',
            ])->findOrFail($validated['topic_id']);

            $this->ownsTopic($topic);

            $this->validateLearningObjectiveBelongsToTopic(
                $validated['learning_objective_id'] ?? null,
                $topic
            );

            $count = (int) ($validated['count'] ?? 5);
            $marks = $validated['marks'] ?? null;
            $instruction = trim($validated['instruction'] ?? '');

            $objective = null;

            if (!empty($validated['learning_objective_id'])) {
                $objective = LearningObjective::find(
                    $validated['learning_objective_id']
                );
            }

            $subjectName = $topic->gradeSubject?->subject?->name
                ?? $topic->gradeSubject?->subject?->subject_name
                ?? 'the subject';

            $gradeName = $topic->gradeSubject?->gradeLevel?->name
                ?? $topic->gradeSubject?->gradeLevel?->grade
                ?? '';

            $unitName = $topic->unit?->name ?? '';

            $objectiveText = $objective?->objective
                ?? $objective?->description
                ?? '';

            $typeInstructions = match ($validated['question_type']) {
                'mcq' => 'Each question must have exactly 4 distinct options and one correct answer. Return options as an array of strings and correct_answer as the correct option string.',
                'true_false' => 'Return options as ["True", "False"] and correct_answer as either "True" or "False".',
                'short_answer' => 'Return options as an empty array and correct_answer as the expected answer or key answer points.',
                'matching' => 'Return options as an object with left and right arrays. Return correct_answer as an array of objects containing left_index and right_index.',
                default => '',
            };

            $instructionText = $instruction !== ''
                ? "Additional teacher instruction:\n{$instruction}\n"
                : '';

            $marksText = $marks !== null
                ? "Default marks for each generated question: {$marks}.\n"
                : '';

            $optionsExample = $validated['question_type'] === 'matching'
                ? '{"left":["Item 1"],"right":["Description 1"]}'
                : '[]';

            $answerExample = $validated['question_type'] === 'matching'
                ? '[{"left_index":0,"right_index":0}]'
                : '""';

            $prompt = <<<PROMPT
Generate {$count} original assessment question(s) for the teacher.

CURRICULUM CONTEXT
Subject: {$subjectName}
Grade: {$gradeName}
Unit: {$unitName}
Topic: {$topic->name}
Learning objective: {$objectiveText}
Question type: {$validated['question_type']}
Difficulty: {$validated['difficulty_level']}
{$marksText}{$instructionText}
QUESTION REQUIREMENTS
- Questions must be directly relevant to the specified topic and learning objective when one is provided.
- Match the requested difficulty level.
- Make every question different from the others in wording and content.
- Do not create duplicate or near-duplicate questions.
- Do not introduce unrelated curriculum content.
- Use clear teacher-ready language.
- For mathematical content, use standard LaTeX notation where appropriate.
- {$typeInstructions}

Return ONLY valid JSON in exactly this structure:
{
  "questions": [
    {
      "question": "Question text",
      "question_type": "{$validated['question_type']}",
      "difficulty_level": "{$validated['difficulty_level']}",
      "options": {$optionsExample},
      "correct_answer": {$answerExample},
      "explanation": "Brief explanation of the answer"
    }
  ]
}
PROMPT;

            $schema = [
                'type' => 'OBJECT',
                'properties' => [
                    'questions' => [
                        'type' => 'ARRAY',
                        'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'question' => [
                                    'type' => 'STRING',
                                ],
                                'question_type' => [
                                    'type' => 'STRING',
                                    'enum' => [
                                        $validated['question_type'],
                                    ],
                                ],
                                'difficulty_level' => [
                                    'type' => 'STRING',
                                    'enum' => [
                                        $validated['difficulty_level'],
                                    ],
                                ],
                                'options' => $validated['question_type'] === 'matching'
                                    ? [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'left' => [
                                                'type' => 'ARRAY',
                                                'items' => ['type' => 'STRING'],
                                            ],
                                            'right' => [
                                                'type' => 'ARRAY',
                                                'items' => ['type' => 'STRING'],
                                            ],
                                        ],
                                        'required' => ['left', 'right'],
                                    ]
                                    : [
                                        'type' => 'ARRAY',
                                        'items' => [
                                            'type' => 'STRING',
                                        ],
                                    ],
                                'correct_answer' => $validated['question_type'] === 'matching'
                                    ? [
                                        'type' => 'ARRAY',
                                        'items' => [
                                            'type' => 'OBJECT',
                                            'properties' => [
                                                'left_index' => ['type' => 'INTEGER'],
                                                'right_index' => ['type' => 'INTEGER'],
                                            ],
                                            'required' => ['left_index', 'right_index'],
                                        ],
                                    ]
                                    : [
                                        'type' => 'STRING',
                                    ],
                                'explanation' => [
                                    'type' => 'STRING',
                                ],
                            ],
                            'required' => [
                                'question',
                                'question_type',
                                'difficulty_level',
                                'options',
                                'correct_answer',
                                'explanation',
                            ],
                        ],
                    ],
                ],
                'required' => [
                    'questions',
                ],
            ];

            $aiResponse = $this->ai->json(
                [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert assessment-question generator. Generate accurate, curriculum-aligned questions and return only the requested JSON structure.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                $schema,
                [
                    'temperature' => 0.7,
                    'max_tokens' => 8192,
                ]
            );

            $generatedQuestions = $aiResponse['questions'] ?? [];

            if (!is_array($generatedQuestions)) {
                throw new \RuntimeException(
                    'AI returned an invalid question list.'
                );
            }

            $generatedQuestions = array_values(
                array_slice($generatedQuestions, 0, $count)
            );

            foreach ($generatedQuestions as &$q) {
                if (!is_array($q)) {
                    $q = [
                        'question' => (string) $q,
                    ];
                }

                $q['topic_id'] = $topic->id;
                $q['learning_objective_id'] =
                    $validated['learning_objective_id'] ?? null;
                $q['question_type'] =
                    $q['question_type'] ?? $validated['question_type'];
                $q['difficulty_level'] =
                    $q['difficulty_level'] ?? $validated['difficulty_level'];
                $q['created_by'] = auth()->id();
                $q['source'] = 'ai';
                $q['status'] = 'draft';
                $q['is_assessment_eligible'] = false;

                if (!isset($q['options']) || !is_array($q['options'])) {
                    $q['options'] = [];
                }

                if (
                    isset($q['correct_answer']) &&
                    is_string($q['correct_answer'])
                ) {
                    $decodedAnswer = json_decode(
                        $q['correct_answer'],
                        true
                    );

                    if (
                        json_last_error() === JSON_ERROR_NONE &&
                        is_array($decodedAnswer)
                    ) {
                        $q['correct_answer'] = $decodedAnswer;
                    }
                }

                $hasMathInQuestion =
                    isset($q['question']) &&
                    is_string($q['question']) &&
                    $this->containsLatexMath($q['question']);

                $hasMathInOptions =
                    isset($q['options']) &&
                    is_array($q['options']) &&
                    $this->optionsContainLatexMath($q['options']);

                if ($hasMathInQuestion) {
                    $q['question'] =
                        $this->normalizeMathQuestion($q['question']);
                }

                if ($hasMathInOptions) {
                    $q['options'] =
                        $this->normalizeMathOptions($q['options']);
                }

                $q['is_math'] =
                    $hasMathInQuestion || $hasMathInOptions;

                if ($q['question_type'] === 'mcq') {
                    $q['correct_answer'] =
                        $this->normalizeAnswerArray(
                            $q['correct_answer'] ?? []
                        );
                }

                if ($q['question_type'] === 'true_false') {
                    $q['options'] = [
                        'True',
                        'False',
                    ];

                    $q['correct_answer'] =
                        $this->normalizeAnswerArray(
                            $q['correct_answer'] ?? ['True']
                        );
                }

                if ($q['question_type'] === 'short_answer') {
                    if (!isset($q['correct_answer'])) {
                        $q['correct_answer'] = [];
                    }
                }

                if ($q['question_type'] === 'matching') {
                    [$matchingOptions, $matchingPairs] =
                        $this->normalizeAIMatchingQuestion(
                            $q['options'] ?? [],
                            $q['correct_answer'] ?? []
                        );

                    $q['options'] = $matchingOptions;
                    $q['correct_answer'] = $matchingPairs;
                }

                if ($marks !== null) {
                    $q['marks'] = $marks;
                }
            }
            unset($q);

            return response()->json([
                'success' => true,
                'data' => $generatedQuestions,
            ]);
        } catch (\Throwable $e) {
            Log::error(
                'AI question generation failed',
                [
                    'user_id' => auth()->id(),
                    'provider' => config('services.ai.provider', 'gemini'),
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Failed to generate questions: ' .
                    $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STORE AI QUESTIONS
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize AI matching output to the Question Bank contract.
     */
    private function normalizeAIMatchingQuestion($options, $correctAnswer): array
    {
        $options = $this->decodeArray($options ?? []);
        $correctAnswer = $this->decodeArray($correctAnswer ?? []);

        $left = [];
        $right = [];

        if (isset($options['left'], $options['right'])) {
            $left = array_values(array_map(fn ($value) => trim((string) $value), is_array($options['left']) ? $options['left'] : []));
            $right = array_values(array_map(fn ($value) => trim((string) $value), is_array($options['right']) ? $options['right'] : []));
        } elseif (is_array($options) && array_is_list($options)) {
            $values = array_values(array_map(fn ($value) => trim((string) $value), $options));
            $half = (int) ceil(count($values) / 2);
            $left = array_slice($values, 0, $half);
            $right = array_slice($values, $half);
        }

        $pairs = [];

        if (is_array($correctAnswer) && !array_is_list($correctAnswer)) {
            foreach ($correctAnswer as $leftText => $rightText) {
                $leftIndex = array_search((string) $leftText, $left, true);
                $rightIndex = array_search((string) $rightText, $right, true);
                if ($leftIndex !== false && $rightIndex !== false) {
                    $pairs[] = ['left_index' => $leftIndex, 'right_index' => $rightIndex];
                }
            }
        } elseif (is_array($correctAnswer)) {
            foreach ($correctAnswer as $pair) {
                if (!is_array($pair)) continue;
                if (isset($pair['left_index'], $pair['right_index'])) {
                    $pairs[] = ['left_index' => (int) $pair['left_index'], 'right_index' => (int) $pair['right_index']];
                    continue;
                }
                $leftText = $pair['left'] ?? $pair['question'] ?? null;
                $rightText = $pair['right'] ?? $pair['answer'] ?? null;
                if ($leftText !== null && $rightText !== null) {
                    $leftIndex = array_search((string) $leftText, $left, true);
                    $rightIndex = array_search((string) $rightText, $right, true);
                    if ($leftIndex !== false && $rightIndex !== false) {
                        $pairs[] = ['left_index' => $leftIndex, 'right_index' => $rightIndex];
                    }
                }
            }
        }

        if (empty($pairs) && count($left) === count($right)) {
            foreach ($left as $index => $_) {
                $pairs[] = ['left_index' => $index, 'right_index' => $index];
            }
        }

        return [
            ['left' => $left, 'right' => $right],
            $pairs,
        ];
    }

    public function storeAIQuestions(Request $request)
    {
        $incomingList = $request->input('questions');

        $items = is_array($incomingList) && count($incomingList) > 0 ? $incomingList : [$request->all()];
        $created = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $errors[$index] = [
                        'question' => ['Invalid question payload.',],
                    ];
                    continue;
                }
                if (
                    !isset($item['topic_id']) && $request->has('topic_id')
                ) {
                    $item['topic_id'] =$request->input('topic_id');
                }

                if (
                    !isset($item['learning_objective_id']) && $request->has('learning_objective_id')
                ) {
                    $item['learning_objective_id'] = $request->input('learning_objective_id');
                }

                if (
                    !isset($item['question_type']) && $request->has('question_type')
                ) {
                    $item['question_type'] = $request->input('question_type');
                }

                if (
                    !isset($item['difficulty_level']) && $request->has('difficulty_level')
                ) {
                    $item['difficulty_level'] =$request->input('difficulty_level');
                }

                try {
                    /*
                     * AI-generated questions do not need to send every
                     * Question Bank flag explicitly. Apply the same safe
                     * defaults used by the normal question form before
                     * validateQuestionData() runs.
                     */
                    if (!array_key_exists('is_math', $item)) {
                        $item['is_math'] = false;
                    }

                    if (!array_key_exists('is_chemistry', $item)) {
                        $item['is_chemistry'] = false;
                    }

                    if (!array_key_exists('multiple_answers', $item)) {
                        $item['multiple_answers'] = false;
                    }

                    if (!array_key_exists('is_required', $item)) {
                        $item['is_required'] = true;
                    }

                    $topic = Topic::with('gradeSubject')->findOrFail($item['topic_id'] ?? 0);
                    $this->ownsTopic($topic);
                    $this->validateLearningObjectiveBelongsToTopic($item['learning_objective_id'] ?? null, $topic);
                    if (
                        in_array(
                            $item['question_type'] ?? null,
                            ['mcq', 'true_false'],
                            true
                        )
                    ) {
                        $item['correct_answer'] = $this->normalizeAnswerArray($item['correct_answer'] ?? []);
                    }

                    if (($item['question_type'] ?? null) === 'matching') {
                        [$item['options'], $item['correct_answer']] =
                            $this->normalizeAIMatchingQuestion(
                                $item['options'] ?? [],
                                $item['correct_answer'] ?? []
                            );
                    }

                    $subRequest = new Request($item);
                    $validated = $this->validateQuestionData($subRequest);
                    $validated['question'] = trim($validated['question']);
                    $hasMathInQuestion = $this->containsLatexMath($validated['question']);
                    $hasMathInOptions =
                        isset($validated['options']) &&
                        is_array($validated['options']) &&
                        $this->optionsContainLatexMath($validated['options']);

                    if ($hasMathInQuestion) {
                        $validated['question'] = $this->normalizeMathQuestion($validated['question']);
                    }

                    if ($hasMathInOptions) {
                        $validated['options'] = $this->normalizeMathOptions($validated['options']);
                    }

                    $validated['is_math'] = $hasMathInQuestion || $hasMathInOptions;

                    $this->rejectDuplicateQuestion(
                        (int) $topic->id,
                        $validated['learning_objective_id'] ?? null,
                        $validated['question_type'] ?? null,
                        $validated['question']
                    );

                    if (
                        ($validated['question_type'] ?? null) === 'mcq'
                    ) {
                        $validated['options'] = $this->normalizeMcqOptions($validated['options'] ?? [],[]);
                    }

                    if (
                        ($validated['question_type'] ?? null) === 'matching'
                    ) {
                        $validated['options'] = $this->decodeArray($validated['options'] ?? []);
                        $validated['correct_answer'] = $this->decodeArray($validated['correct_answer'] ?? []);
                    }

                    if (
                        !in_array(
                            $validated['question_type'],
                            ['matching', 'short_answer'],
                            true
                        )
                    ) {
                        $validated['marks'] =
                            $this->autoMarks(
                                $validated['marks'] ?? null,
                                $validated['difficulty_level']
                            );
                    }

                    /*
                     * CRITICAL:
                     * AI questions are drafts.
                     */
                    $validated['created_by'] = auth()->id();
                    $validated['source'] = 'ai';
                    $validated['status'] = 'approved';
                    $validated['is_assessment_eligible'] = true;

                    $question = Question::create($validated);
                    $created[] = $this->normalizeQuestionPayload($question);
                } catch (
                    \Illuminate\Validation\ValidationException $e
                ) {
                    $errors[$index] = $e->errors();
                } catch (\Throwable $e) {
                    $errors[$index] = [
                        'question' => [
                            $e->getMessage(),
                        ],
                    ];
                }
            }

            /*
             * Do not partially save a batch.
             */
            if (!empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $created,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error(
                'AI bulk question save failed',
                [
                    'user_id' => auth()->id(),
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'error' =>
                'Failed to save questions',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | APPROVE AI QUESTION
    |--------------------------------------------------------------------------
    */

    public function approveForAssessment($id)
    {
        $question = Question::with([
            'topic.gradeSubject',
            'subQuestions',
        ])->findOrFail($id);

        $this->ownsQuestion($question);

        DB::transaction(function () use ($question) {
            $question->update([
                'status' => 'approved',
                'is_assessment_eligible' => true,
            ]);

            if ($question->subQuestions) {
                $question->subQuestions()->update([
                    'status' => 'approved',
                    'is_assessment_eligible' => true,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' =>
            'Question approved for assessment.',
            'data' => $this->normalizeQuestionWithSubQuestions(
                $question->fresh()->load([
                    'topic',
                    'learningObjective',
                    'subQuestions.learningObjective',
                ])
            ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | REJECT AI QUESTION
    |--------------------------------------------------------------------------
    */

    public function rejectForAssessment($id)
    {
        $question = Question::with([
            'topic.gradeSubject',
            'subQuestions',
        ])->findOrFail($id);

        $this->ownsQuestion($question);

        DB::transaction(function () use ($question) {
            $question->update([
                'status' => 'rejected',
                'is_assessment_eligible' => false,
            ]);

            if ($question->subQuestions) {
                $question->subQuestions()->update([
                    'status' => 'rejected',
                    'is_assessment_eligible' => false,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' =>
            'Question rejected for assessment.',
            'data' => $this->normalizeQuestionWithSubQuestions(
                $question->fresh()->load([
                    'topic',
                    'learningObjective',
                    'subQuestions.learningObjective',
                ])
            ),
        ]);
    }

    public function byLearningObjective(
        $learningObjectiveId,
        Request $request
    ) {
        $objective = LearningObjective::with([
            'topic.gradeSubject.subject',
            'topic.gradeSubject.gradeLevel',
            'topic.unit',
        ])->findOrFail($learningObjectiveId);

        abort_unless(
            $objective->topic instanceof Topic,
            403,
            'This learning objective is not linked to a valid topic.'
        );

        $this->ownsTopic($objective->topic);

        $pageSize = min(
            max((int) $request->input('page_size', 20), 1),
            100
        );

        $questions = Question::query()
            ->where('learning_objective_id', $objective->id)
            ->whereNull('parent_question_id')
            ->with([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ])
            ->orderBy('id')
            ->paginate($pageSize);

        $questions->getCollection()->transform(
            fn($question) =>
            $this->normalizeQuestionWithSubQuestions($question)
        );

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
    public function assessmentQuestionsByObjective(
        $learningObjectiveId,
        Request $request
    ) {
        $objective = LearningObjective::with([
            'topic.gradeSubject.subject',
            'topic.gradeSubject.gradeLevel',
            'topic.unit',
        ])->findOrFail($learningObjectiveId);

        abort_unless(
            $objective->topic instanceof Topic,
            403,
            'This learning objective is not linked to a valid topic.'
        );

        $this->ownsTopic($objective->topic);

        $pageSize = min(
            max((int) $request->input('page_size', 20), 1),
            100
        );

        $questions = Question::query()
            ->assessmentEligible()
            ->where('learning_objective_id', $objective->id)
            ->whereNull('parent_question_id')
            ->with([
                'topic.gradeSubject.subject',
                'topic.gradeSubject.gradeLevel',
                'topic.unit',
                'learningObjective',
                'subQuestions.learningObjective',
            ])
            ->orderBy('id')
            ->paginate($pageSize);

        $questions->getCollection()->transform(
            fn($question) =>
            $this->normalizeQuestionWithSubQuestions($question)
        );

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

    private function ownsTopic(Topic $topic): void
    {
        $user = auth()->user();
        abort_unless($user, 401, 'Unauthenticated.');
        $topic->loadMissing(['gradeSubject',]);
        $gradeSubject = $topic->gradeSubject;
        abort_unless($gradeSubject instanceof GradeSubject, 403, 'This topic is not linked to a valid teaching area.');
        abort_unless($user->school_id !== null, 403, 'Your account is not linked to a school.');
        abort_unless($gradeSubject->school_id !== null && (int) $gradeSubject->school_id === (int) $user->school_id, 403, 'This topic does not belong to your school.');

        if ($user->role === 'admin') {
            return;
        }

        abort_unless(
            $user->role === 'teacher' &&
                $gradeSubject->teacher_id !== null &&
                (int) $gradeSubject->teacher_id === (int) $user->id,
            403,
            'You are not assigned to this teaching area.'
        );
    }

    private function ownsQuestion(Question $question): void
    {
        $question->loadMissing([
            'topic.gradeSubject',
        ]);

        abort_unless(
            $question->topic instanceof Topic,
            403,
            'This question is not linked to a valid topic.'
        );

        $this->ownsTopic($question->topic);
    }

    private function scopeQuestionsToUser(
        $query,
        $user
    ): void {
        abort_unless(
            $user && $user->school_id !== null,
            403,
            'Your account is not linked to a school.'
        );

        if ($user->role === 'admin') {
            $query->whereHas(
                'topic.gradeSubject',
                function ($q) use ($user) {
                    $q->where(
                        'school_id',
                        $user->school_id
                    );
                }
            );

            return;
        }

        if ($user->role === 'teacher') {
            $query->whereHas(
                'topic.gradeSubject',
                function ($q) use ($user) {
                    $q->where(
                        'school_id',
                        $user->school_id
                    )->where(
                        'teacher_id',
                        $user->id
                    );
                }
            );

            return;
        }

        /*
     * Unknown roles get no questions.
     */
        $query->whereRaw('1 = 0');
    }

    private function validateLearningObjectiveBelongsToTopic(
        $objectiveId,
        Topic $topic
    ): void {
        if (!$objectiveId) {
            return;
        }

        $exists = LearningObjective::where(
            'id',
            $objectiveId
        )
            ->where(
                'topic_id',
                $topic->id
            )
            ->exists();

        abort_unless($exists, 422, 'The learning objective does not belong to the selected topic.');
    }

    private function rejectDuplicateQuestion(
        int $topicId,
        $learningObjectiveId,
        ?string $questionType,
        string $questionText,
        ?int $ignoreId = null
    ): void {
        $fingerprint = Question::buildQuestionFingerprint(
            $topicId,
            $learningObjectiveId ? (int) $learningObjectiveId : null,
            $questionType,
            $questionText
        );

        $query = Question::query()
            ->where('topic_id', $topicId)
            ->whereNull('parent_question_id');

        if ($learningObjectiveId === null || $learningObjectiveId === '') {
            $query->whereNull('learning_objective_id');
        } else {
            $query->where('learning_objective_id', (int) $learningObjectiveId);
        }

        if ($questionType !== null && $questionType !== '') {
            $query->where('question_type', $questionType);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        /*
         * Prefer the persisted fingerprint. Legacy rows are also checked by
         * calculating the same fingerprint in PHP so existing duplicates are
         * protected even before the cleanup migration is run.
         */
        $exists = $query
            ->get()
            ->contains(function (Question $question) use ($fingerprint) {
                $existingFingerprint = $question->question_fingerprint
                    ?: Question::buildQuestionFingerprint(
                        $question->topic_id,
                        $question->learning_objective_id,
                        $question->question_type,
                        $question->question
                    );

                return hash_equals($existingFingerprint, $fingerprint);
            });

        if ($exists) {
            abort(422, 'This question already exists in this topic with the same learning objective and question type.');
        }
    }

    private function autoMarks(
        $marks,
        string $difficulty
    ): int|float {
        if (
            $marks !== null &&
            (float)$marks > 0
        ) {
            return $marks;
        }

        return match ($difficulty) {
            'remembering',
            'understanding' => 1,

            'analyzing' => 2,

            'applying',
            'evaluating' => 3,

            'creating' => 4,

            default => 1,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | MCQ OPTIONS
    |--------------------------------------------------------------------------
    */

    private function normalizeMcqOptions(
        array $options,
        array $imageFiles = []
    ): array {
        $normalized = [];

        foreach ($options as $index => $text) {
            $text =
                is_string($text)
                ? trim($text)
                : '';

            $imagePath = null;

            if (
                isset($imageFiles[$index]) &&
                $imageFiles[$index]
            ) {
                $imagePath =
                    $imageFiles[$index]
                    ->store('options', 'public');
            }

            $normalized[] = [
                'text' =>
                $text !== ''
                    ? $text
                    : null,
                'image' => $imagePath,
            ];
        }

        return $normalized;
    }

    private function normalizeMcqOptionsForUpdate(
        array $options,
        array $imageFiles,
        array $currentOptions
    ): array {
        $normalized = [];

        foreach ($options as $index => $text) {
            $text =
                is_string($text)
                ? trim($text)
                : '';

            $imagePath =
                $currentOptions[$index]['image']
                ?? null;

            if (
                isset($imageFiles[$index]) &&
                $imageFiles[$index]
            ) {
                if ($imagePath) {
                    Storage::disk('public')
                        ->delete($imagePath);
                }

                $imagePath =
                    $imageFiles[$index]
                    ->store('options', 'public');
            }

            $normalized[] = [
                'text' =>
                $text !== ''
                    ? $text
                    : null,
                'image' => $imagePath,
            ];
        }

        /*
         * Remove old option images that are no longer used.
         */
        foreach ($currentOptions as $index => $oldOption) {
            if (
                isset($oldOption['image']) &&
                $oldOption['image'] &&
                !isset($normalized[$index])
            ) {
                Storage::disk('public')
                    ->delete($oldOption['image']);
            }
        }

        return $normalized;
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE HELPERS
    |--------------------------------------------------------------------------
    */

    private function handleQuestionImage(
        Request $request
    ): string {
        $image =
            $request->file('question_image');

        if (
            $image &&
            $image->getSize() > 4096 * 1024
        ) {
            throw new \Exception(
                'Image size exceeds maximum allowed size.'
            );
        }

        return $image->store(
            'questions',
            'public'
        );
    }

    private function deleteQuestionImages(
        Question $question
    ): void {
        if ($question->question_image) {
            Storage::disk('public')->delete(
                $question->question_image
            );
        }

        if ($question->correct_answer_image) {
            Storage::disk('public')->delete(
                $question->correct_answer_image
            );
        }

        $options = $question->options ?? [];

        if (is_array($options)) {
            foreach ($options as $option) {
                if (
                    is_array($option) &&
                    !empty($option['image'])
                ) {
                    Storage::disk('public')->delete(
                        $option['image']
                    );
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | JSON HELPERS
    |--------------------------------------------------------------------------
    */

    private function decodeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded =
            json_decode($value, true);

        return
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
            ? $decoded
            : [];
    }

    private function normalizeAnswerArray($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return is_array($value)
            ? $value
            : [$value];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE NORMALIZATION
    |--------------------------------------------------------------------------
    */

    private function normalizeQuestionWithSubQuestions(
        Question $question
    ) {
        $parent =
            $this->normalizeQuestionPayload(
                $question
            );

        $parent->sub_questions =
            $question->subQuestions
            ->map(
                fn($sub) =>
                $this->normalizeQuestionPayload(
                    $sub
                )
            )
            ->values();

        /*
         * Parent container is represented as a container
         * when it has subquestions.
         */
        if (
            $parent->sub_questions->count() > 0
        ) {
            $parent->question_type = null;
        }

        return $parent;
    }

    private function normalizeQuestionPayload(
        $question
    ) {
        /*
         * Options.
         */
        if (
            is_string($question->options)
        ) {
            $decoded =
                json_decode(
                    $question->options,
                    true
                );

            if (
                json_last_error() ===
                JSON_ERROR_NONE
            ) {
                $question->options =
                    $decoded;
            }
        }

        if (
            is_array($question->options)
        ) {
            $question->options =
                array_map(
                    fn($option) =>
                    $this->decodeJsonIfNeeded(
                        $option
                    ),
                    $question->options
                );
        }

        /*
         * Correct answer.
         */
        if (
            isset($question->correct_answer)
        ) {
            $question->correct_answer =
                $this->decodeJsonIfNeeded(
                    $question->correct_answer
                );
        }

        /*
         * Image URLs.
         */
        $question->question_image_url =
            $question->question_image
            ? asset(
                'storage/' .
                    $question->question_image
            )
            : null;

        $question->correct_answer_image_url =
            $question->correct_answer_image
            ? asset(
                'storage/' .
                    $question->correct_answer_image
            )
            : null;

        /*
         * Metadata / KaTeX.
         */
        if (!empty($question->metadata)) {
            $metadata =
                is_string($question->metadata)
                ? json_decode(
                    $question->metadata,
                    true
                )
                : $question->metadata;

            if (is_array($metadata)) {
                $question->katex_content =
                    $metadata['katex_content']
                    ?? null;
            }
        }

        return $question;
    }

    private function decodeJsonIfNeeded($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        if (
            (
                str_starts_with($trimmed, '[') &&
                str_ends_with($trimmed, ']')
            ) ||
            (
                str_starts_with($trimmed, '{') &&
                str_ends_with($trimmed, '}')
            )
        ) {
            $decoded =
                json_decode(
                    $trimmed,
                    true
                );

            if (
                json_last_error() ===
                JSON_ERROR_NONE
            ) {
                return $decoded;
            }
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | MATH HELPERS
    |--------------------------------------------------------------------------
    */

    private function containsLatexMath(
        string $text
    ): bool {
        $needles = [
            '\\(',
            '\\)',
            '$',
            '\\frac',
            '\\sqrt',
            '\\sum',
            '\\int',
            '^',
        ];

        foreach ($needles as $needle) {
            if (
                strpos($text, $needle) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    private function optionsContainLatexMath(
        array $options
    ): bool {
        foreach ($options as $option) {
            if (
                is_string($option) &&
                $this->containsLatexMath($option)
            ) {
                return true;
            }

            if (
                is_array($option) &&
                isset($option['text']) &&
                is_string($option['text']) &&
                $this->containsLatexMath(
                    $option['text']
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function wrapInlineLatex(
        string $text
    ): string {
        $trimmed = trim($text);

        if (
            preg_match(
                '/^\$.*\$$/s',
                $trimmed
            )
        ) {
            return $trimmed;
        }

        if (
            preg_match(
                '/^\\\((.*)\\\)$/s',
                $trimmed,
                $matches
            )
        ) {
            return '$' .
                $matches[1] .
                '$';
        }

        return '$' .
            $trimmed .
            '$';
    }

    private function normalizeMathOptions(
        array $options
    ): array {
        return array_map(
            function ($option) {
                if (is_string($option)) {
                    $trimmed = trim($option);

                    if (
                        $this->containsLatexMath(
                            $trimmed
                        )
                    ) {
                        return $this->wrapInlineLatex(
                            $trimmed
                        );
                    }

                    return $trimmed;
                }

                if (
                    is_array($option) &&
                    isset($option['text']) &&
                    is_string($option['text'])
                ) {
                    $text =
                        trim($option['text']);

                    $option['text'] =
                        $this->containsLatexMath(
                            $text
                        )
                        ? $this->wrapInlineLatex(
                            $text
                        )
                        : $text;

                    return $option;
                }

                return $option;
            },
            $options
        );
    }

    private function normalizeMathQuestion(
        string $question
    ): string {
        $trimmed = trim($question);
        if (!$this->containsLatexMath($trimmed)) {
            return $trimmed;
        }
        $wordCount = str_word_count($trimmed);
        if (
            $wordCount <= 2 &&
            !preg_match(
                '/[.!?]/',
                $trimmed
            )
        ) {
            return $this->wrapInlineLatex($trimmed);
        }
        $wrapped =  preg_replace_callback(
            '/([A-Za-z0-9()]+\^[A-Za-z0-9()]+)/',
            function ($matches) {
                return $this->wrapInlineLatex(
                    $matches[1]
                );
            },
            $trimmed
        );

        return $wrapped !== null
            ? $wrapped
            : $trimmed;
    }
}