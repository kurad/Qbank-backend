<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessQuestionnaireImport;
use App\Models\GradeSubject;
use App\Models\Question;
use App\Models\QuestionnaireImport;
use App\Models\QuestionnaireImportItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class QuestionnaireImportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $imports = QuestionnaireImport::query()
            ->where('user_id', $user->id)
            ->with([
                'gradeSubject.subject:id,name',
                'gradeSubject.gradeLevel:id,name',
            ])
            ->latest()
            ->paginate(
                min(
                    50,
                    max(
                        5,
                        $request->integer('per_page', 15)
                    )
                )
            );

        return response()->json($imports);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grade_subject_id' => [
                'required',
                'integer',
                'exists:grade_subjects,id',
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf,docx,txt,png,jpg,jpeg,webp',
                'max:15360',
            ],
        ]);

        $gradeSubject = GradeSubject::with('school')
            ->findOrFail(
                $validated['grade_subject_id']
            );

        $this->authorizeTeachingArea(
            $request,
            $gradeSubject
        );

        $file = $request->file('file');

        $path = $file->store(
            'questionnaire-imports',
            'local'
        );

        $import = QuestionnaireImport::create([
            'user_id' => $request->user()->id,
            'grade_subject_id' => $gradeSubject->id,
            'original_name' =>
                $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' =>
                $file->getMimeType()
                ?: $file->getClientMimeType(),
            'status' => 'queued',
        ]);

        ProcessQuestionnaireImport::dispatch(
            $import->id
        );

        return response()->json([
            'message' =>
                'Questionnaire uploaded. Extraction has started.',
            'data' => $this->loadImport($import),
        ], 202);
    }

    public function show(
        Request $request,
        QuestionnaireImport $questionnaireImport
    ) {
        $this->authorizeImport(
            $request,
            $questionnaireImport
        );

        return response()->json([
            'data' =>
                $this->loadImport(
                    $questionnaireImport
                ),
        ]);
    }

    public function reprocess(
        Request $request,
        QuestionnaireImport $questionnaireImport
    ) {
        $this->authorizeImport(
            $request,
            $questionnaireImport
        );

        $questionnaireImport->update([
            'status' => 'queued',
            'error_message' => null,
        ]);

        ProcessQuestionnaireImport::dispatch(
            $questionnaireImport->id
        );

        return response()->json([
            'message' => 'Questionnaire reprocessing started.',
        ]);
    }

    public function updateItem(
        Request $request,
        QuestionnaireImport $questionnaireImport,
        QuestionnaireImportItem $item
    ) {
        $this->authorizeImport(
            $request,
            $questionnaireImport
        );

        abort_unless(
            (int) $item->questionnaire_import_id ===
            (int) $questionnaireImport->id,
            404
        );

        $validated = $request->validate([
            'question_type' => [
                'required',
                Rule::in([
                    'mcq',
                    'true_false',
                    'short_answer',
                    'fill_blank',
                    'matching',
                    'open_ended',
                ]),
            ],
            'question' => [
                'required',
                'string',
            ],
            'options' => [
                'nullable',
                'array',
            ],
            'correct_answer' => [
                'nullable',
                'array',
            ],
            'marks' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'difficulty_level' => [
                'required',
                Rule::in([
                    'remembering',
                    'understanding',
                    'applying',
                    'analyzing',
                    'evaluating',
                    'creating',
                ]),
            ],
            'is_math' => [
                'required',
                'boolean',
            ],
            'is_chemistry' => [
                'required',
                'boolean',
            ],
            'explanation' => [
                'nullable',
                'string',
            ],
            'unit_id' => [
                'nullable',
                'integer',
                'exists:units,id',
            ],
            'topic_id' => [
                'nullable',
                'integer',
                'exists:topics,id',
            ],
            'learning_objective_id' => [
                'nullable',
                'integer',
                'exists:learning_objectives,id',
            ],
            'mapping_confidence' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'status' => [
                'nullable',
                Rule::in([
                    'pending',
                    'approved',
                    'rejected',
                ]),
            ],
        ]);

        $this->validateMapping(
            $questionnaireImport->grade_subject_id,
            $validated
        );

        $fingerprint = null;

        if (!empty($validated['topic_id'])) {
            $fingerprint =
                Question::buildQuestionFingerprint(
                    (int) $validated['topic_id'],
                    isset(
                        $validated['learning_objective_id']
                    )
                        ? (int) $validated['learning_objective_id']
                        : null,
                    $validated['question_type'],
                    $validated['question']
                );
        }

        $validated['duplicate_question_id'] =
            $fingerprint
                ? Question::query()
                    ->where(
                        'question_fingerprint',
                        $fingerprint
                    )
                    ->value('id')
                : null;

        $item->update($validated);

        return response()->json([
            'message' =>
                'Questionnaire item updated.',
            'data' =>
                $item->fresh([
                    'unit:id,name',
                    'topic:id,topic_name,unit_id',
                    'learningObjective:id,topic_id,code,objective',
                    'duplicateQuestion:id,question',
                ]),
        ]);
    }

    public function approve(
        Request $request,
        QuestionnaireImport $questionnaireImport
    ) {
        $this->authorizeImport(
            $request,
            $questionnaireImport
        );

        $validated = $request->validate([
            'item_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'item_ids.*' => [
                'integer',
            ],
            'allow_duplicates' => [
                'nullable',
                'boolean',
            ],
        ]);

        $items = $questionnaireImport->items()
            ->whereIn('id', $validated['item_ids'])
            ->whereIn(
                'status',
                ['pending', 'approved']
            )
            ->get();

        if ($items->count() !==
            count(
                array_unique(
                    $validated['item_ids']
                )
            )
        ) {
            return response()->json([
                'message' =>
                    'Some selected questions are unavailable for import.',
            ], 422);
        }

        $created = [];

        DB::transaction(
            function () use (
                $request,
                $questionnaireImport,
                $items,
                $validated,
                &$created
            ) {
                foreach ($items as $item) {
                    if (!$item->topic_id) {
                        abort(
                            422,
                            "Question {$item->source_order} must be mapped to a topic before approval."
                        );
                    }

                    if (
                        $item->duplicate_question_id &&
                        empty(
                            $validated['allow_duplicates']
                        )
                    ) {
                        abort(
                            422,
                            "Question {$item->source_order} matches an existing Question Bank item."
                        );
                    }

                    $this->validateItemForImport(
                        $item
                    );

                    $options =
                        $this->normalizeQuestionOptions(
                            $item
                        );

                    $question = Question::create([
                        'topic_id' => $item->topic_id,
                        'learning_objective_id' =>
                            $item->learning_objective_id,
                        'question_type' =>
                            $item->question_type,
                        'question' =>
                            trim($item->question),
                        'options' => $options,
                        'correct_answer' =>
                            $item->correct_answer ?: null,
                        'marks' =>
                            $item->marks ?? 1,
                        'difficulty_level' =>
                            $item->difficulty_level,
                        'is_math' =>
                            $item->is_math,
                        'is_chemistry' =>
                            $item->is_chemistry,
                        'multiple_answers' =>
                            $item->question_type === 'mcq' &&
                            count(
                                $item->correct_answer ?: []
                            ) > 1,
                        'is_required' => false,
                        'explanation' =>
                            $item->explanation,
                        'created_by' =>
                            $request->user()->id,
                        'source' => 'import',
                        'status' => 'approved',
                        'is_assessment_eligible' => true,
                    ]);

                    $item->update([
                        'status' => 'imported',
                        'created_question_id' =>
                            $question->id,
                    ]);

                    $created[] = $question->id;
                }

                $remaining =
                    $questionnaireImport
                    ->items()
                    ->whereNotIn(
                        'status',
                        ['imported', 'rejected']
                    )
                    ->exists();

                if (!$remaining) {
                    $questionnaireImport->update([
                        'status' => 'completed',
                    ]);
                }
            }
        );

        return response()->json([
            'message' =>
                count($created) .
                ' question(s) added to the Question Bank.',
            'question_ids' => $created,
        ]);
    }

    public function destroy(
        Request $request,
        QuestionnaireImport $questionnaireImport
    ) {
        $this->authorizeImport(
            $request,
            $questionnaireImport
        );

        Storage::disk('local')->delete(
            $questionnaireImport->file_path
        );

        $questionnaireImport->delete();

        return response()->json([
            'message' =>
                'Questionnaire import deleted.',
        ]);
    }

    protected function loadImport(
        QuestionnaireImport $import
    ): QuestionnaireImport {
        return $import->load([
            'gradeSubject.subject:id,name',
            'gradeSubject.gradeLevel:id,name',
            'gradeSubject.units:id,grade_subject_id,name,order,status',
            'gradeSubject.units.topics:id,unit_id,grade_subject_id,topic_name,order,status',
            'gradeSubject.units.topics.learningObjectives:id,topic_id,code,objective,order,status',
            'items.unit:id,name',
            'items.topic:id,topic_name,unit_id',
            'items.learningObjective:id,topic_id,code,objective',
            'items.duplicateQuestion:id,question',
            'items.createdQuestion:id,question',
        ]);
    }

    protected function authorizeImport(
        Request $request,
        QuestionnaireImport $import
    ): void {
        abort_unless(
            (int) $import->user_id ===
            (int) $request->user()->id,
            403,
            'You cannot manage this questionnaire import.'
        );
    }

    protected function authorizeTeachingArea(
        Request $request,
        GradeSubject $gradeSubject
    ): void {
        $user = $request->user();

        if ($user->role === 'teacher') {
            abort_unless(
                (int) $gradeSubject->teacher_id ===
                (int) $user->id,
                403,
                'You can only import questions into your own teaching area.'
            );

            return;
        }

        if ($user->role === 'admin') {
            abort_unless(
                (int) $gradeSubject->school_id ===
                (int) $user->school_id,
                403,
                'This teaching area belongs to another school.'
            );

            return;
        }

        abort(
            403,
            'Only teachers and administrators can import questionnaires.'
        );
    }

    protected function validateMapping(
        int $gradeSubjectId,
        array $payload
    ): void {
        $unitId =
            $payload['unit_id'] ?? null;
        $topicId =
            $payload['topic_id'] ?? null;
        $objectiveId =
            $payload['learning_objective_id'] ?? null;

        if ($unitId) {
            abort_unless(
                DB::table('units')
                    ->where('id', $unitId)
                    ->where(
                        'grade_subject_id',
                        $gradeSubjectId
                    )
                    ->exists(),
                422,
                'Selected unit does not belong to this teaching area.'
            );
        }

        if ($topicId) {
            $topic = DB::table('topics')
                ->where('id', $topicId)
                ->where(
                    'grade_subject_id',
                    $gradeSubjectId
                )
                ->first();

            abort_unless(
                $topic,
                422,
                'Selected topic does not belong to this teaching area.'
            );

            if ($unitId) {
                abort_unless(
                    (int) $topic->unit_id ===
                    (int) $unitId,
                    422,
                    'Selected topic does not belong to the selected unit.'
                );
            }
        }

        if ($objectiveId) {
            abort_unless(
                $topicId &&
                DB::table('learning_objectives')
                    ->where('id', $objectiveId)
                    ->where(
                        'topic_id',
                        $topicId
                    )
                    ->exists(),
                422,
                'Selected learning objective does not belong to the selected topic.'
            );
        }
    }

    protected function validateItemForImport(
        QuestionnaireImportItem $item
    ): void {
        if (
            $item->question_type === 'mcq' &&
            count($item->options ?: []) < 2
        ) {
            abort(
                422,
                "Question {$item->source_order} needs at least two MCQ options."
            );
        }

        if (
            $item->question_type === 'fill_blank' &&
            count($item->correct_answer ?: []) < 1
        ) {
            abort(
                422,
                "Question {$item->source_order} needs at least one accepted answer."
            );
        }

        if ($item->question_type === 'matching') {
            $left =
                $item->options['left'] ?? [];
            $right =
                $item->options['right'] ?? [];

            if (
                count($left) < 1 ||
                count($right) < 1
            ) {
                abort(
                    422,
                    "Question {$item->source_order} needs matching left and right items."
                );
            }
        }
    }

    protected function normalizeQuestionOptions(
        QuestionnaireImportItem $item
    ): ?array {
        if ($item->question_type === 'mcq') {
            return array_map(
                fn ($option) => [
                    'text' =>
                        trim((string) $option) !== ''
                            ? trim((string) $option)
                            : null,
                    'image' => null,
                ],
                $item->options ?: []
            );
        }

        if ($item->question_type === 'true_false') {
            return [
                [
                    'text' => 'True',
                    'image' => null,
                ],
                [
                    'text' => 'False',
                    'image' => null,
                ],
            ];
        }

        if ($item->question_type === 'matching') {
            return $item->options ?: [
                'left' => [],
                'right' => [],
            ];
        }

        return null;
    }
}
