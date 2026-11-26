<?php

namespace App\Services;

use App\Exceptions\FormativePreparationException;
use App\Models\LearningObjective;
use App\Models\LearningPeriod;
use App\Models\Question;
use App\Models\Topic;
use App\Services\AI\AIGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the questions a learner's formative assessment is missing.
 *
 * Differences from the earlier per-service copies of this code:
 *  - Evidence is the teacher's APPROVED material for the objective (the same
 *    evidence the curriculum gate and coverage checker use), never the first
 *    few chunks of the topic and never the objective text alone.
 *  - Every AI item is validated and repaired before it is stored. An item that
 *    could not be graded (MCQ without the right answer among its options,
 *    empty text, unknown type ...) is dropped instead of reaching a student.
 *  - A short or partly unusable AI answer is topped up with further rounds
 *    instead of failing the whole assessment.
 *  - Generated questions are kept as a shared pool per objective, so the next
 *    learner reuses them (no repeated AI cost) and a retried job resumes where
 *    the previous run stopped.
 */
class FormativeQuestionGenerator
{
    public const SOURCE = 'ai_mastery';

    public const TYPES = ['mcq', 'true_false', 'fill_blank', 'short_answer'];

    private const MAX_ROUNDS = 3;

    private const MAX_EVIDENCE_CHARS = 14000;

    private const MIN_QUESTION_CHARS = 8;

    private const BLOOM = [
        'remembering', 'understanding', 'applying',
        'analyzing', 'evaluating', 'creating',
    ];

    public function __construct(
        protected AIGateway $ai,
        protected ObjectiveCoverageService $coverage
    ) {
    }

    /* ------------------------------------------------------------------ *
     * Evidence
     * ------------------------------------------------------------------ */

    public function evidence(LearningObjective $objective, Topic $topic): string
    {
        $topic->loadMissing('gradeSubject');
        $objective->setRelation('topic', $topic);

        return trim((string) ($this->coverage->evidence($objective)['text'] ?? ''));
    }

    public function hasEvidence(LearningObjective $objective, Topic $topic): bool
    {
        return $this->evidence($objective, $topic) !== '';
    }

    /* ------------------------------------------------------------------ *
     * Shared pool of previously generated questions
     * ------------------------------------------------------------------ */

    /**
     * Valid questions already generated for this objective. Questions created
     * before the objective was last edited are stale and not reused.
     */
    public function reusablePool(LearningObjective $objective): Collection
    {
        return Question::query()
            ->where('learning_objective_id', $objective->id)
            ->where('source', self::SOURCE)
            ->whereNull('parent_question_id')
            ->whereIn('question_type', self::TYPES)
            ->when(
                $objective->updated_at,
                fn ($query, $updatedAt) => $query->where('created_at', '>=', $updatedAt)
            )
            ->orderBy('id')
            ->get()
            ->filter(fn (Question $question) => $this->isUsable($question))
            ->values();
    }

    /** Re-validate a stored question with the same rules used at creation. */
    public function isUsable(Question $question): bool
    {
        return $this->normaliseItem([
            'question' => $question->question,
            'question_type' => $question->question_type,
            'options' => $question->options,
            'correct_answer' => $question->correct_answer,
            'difficulty_level' => $question->difficulty_level,
        ]) !== null;
    }

    /* ------------------------------------------------------------------ *
     * Generation
     * ------------------------------------------------------------------ */

    /**
     * Return up to $count usable questions for the objective, reusing the pool
     * first and asking the AI only for what is still missing.
     *
     * Provider outages propagate (the job retries; whatever was stored so far
     * is kept). An AI that answers but produces too few usable questions
     * yields a shorter collection - the caller decides what to do about it.
     *
     * @param  int[]    $excludeIds  questions the caller already holds
     * @param  string[] $avoidTexts  question texts that must not be repeated
     * @param  array    $usage       _usage_* options for AI usage logging
     *
     * @throws FormativePreparationException when the objective has no approved material
     */
    public function generate(
        LearningPeriod $period,
        Topic $topic,
        string $unitName,
        LearningObjective $objective,
        int $count,
        array $excludeIds = [],
        array $avoidTexts = [],
        array $usage = []
    ): Collection {
        if ($count <= 0) {
            return collect();
        }

        $evidence = $this->evidence($objective, $topic);

        if ($evidence === '') {
            throw new FormativePreparationException(
                'The approved learning material does not cover this learning goal, so no questions can be prepared for it.'
            );
        }

        // One generator per objective at a time: learners who finish together
        // wait for the first one and then reuse its questions.
        $lock = null;
        $acquired = false;

        try {
            $lock = Cache::lock('formative-gen:objective:' . $objective->id, 240);
            $acquired = (bool) $lock->block(120);
        } catch (Throwable $e) {
            // No lock support or wait timed out: carry on without it.
        }

        try {
            return $this->generateLocked(
                $period, $topic, $unitName, $objective, $count,
                $excludeIds, $avoidTexts, $usage, $evidence
            );
        } finally {
            if ($acquired && $lock) {
                try {
                    $lock->release();
                } catch (Throwable $e) {
                    // The lock expires on its own.
                }
            }
        }
    }

    protected function generateLocked(
        LearningPeriod $period,
        Topic $topic,
        string $unitName,
        LearningObjective $objective,
        int $count,
        array $excludeIds,
        array $avoidTexts,
        array $usage,
        string $evidence
    ): Collection {
        // Another worker may have produced questions while we waited.
        $result = $this->reusablePool($objective)
            ->reject(fn (Question $q) => in_array($q->id, $excludeIds, true))
            ->take($count)
            ->values();

        $seen = [];
        foreach (array_merge($avoidTexts, $result->pluck('question')->all()) as $text) {
            $seen[Question::normalizeQuestionText((string) $text)] = true;
        }

        $remaining = $count - $result->count();
        $rejected = 0;

        for ($round = 1; $round <= self::MAX_ROUNDS && $remaining > 0; $round++) {
            // Ask for a few more than needed: some items will not validate.
            $ask = $remaining + max(1, (int) ceil($remaining * 0.25));

            $items = $this->requestQuestions(
                $period, $topic, $unitName, $objective, $ask,
                array_keys($seen), $evidence, $usage
            );

            foreach ($items as $raw) {
                if ($remaining <= 0) {
                    break;
                }

                $clean = is_array($raw) ? $this->normaliseItem($raw) : null;

                if ($clean === null) {
                    $rejected++;
                    continue;
                }

                $key = Question::normalizeQuestionText($clean['question']);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $result->push($this->store($period, $topic, $objective, $clean));
                $remaining--;
            }
        }

        if ($remaining > 0) {
            Log::warning('Formative generation returned fewer usable questions than required.', [
                'learning_objective_id' => $objective->id,
                'required' => $count,
                'produced' => $count - $remaining,
                'rejected_items' => $rejected,
            ]);
        }

        return $result->values();
    }

    /** @return array<int, mixed> */
    protected function requestQuestions(
        LearningPeriod $period,
        Topic $topic,
        string $unitName,
        LearningObjective $objective,
        int $ask,
        array $avoidNormalised,
        string $evidence,
        array $usage
    ): array {
        $subject = $period->gradeSubject?->subject?->name ?? 'Subject';
        $topicName = $topic->topic_name ?? 'Topic';
        $objectiveText = trim(
            ($objective->code ? $objective->code . ': ' : '') .
            ($objective->objective ?? $objective->description ?? '')
        );
        $material = mb_substr($evidence, 0, self::MAX_EVIDENCE_CHARS);
        $bloom = implode(', ', self::BLOOM);

        $avoid = '';
        if (!empty($avoidNormalised)) {
            $lines = array_map(
                fn ($text) => '- ' . mb_substr((string) $text, 0, 160),
                array_slice(array_values($avoidNormalised), 0, 25)
            );
            $avoid = "\nDO NOT REPEAT OR CLOSELY PARAPHRASE THESE EXISTING QUESTIONS:\n" . implode("\n", $lines) . "\n";
        }

        $prompt = <<<PROMPT
Create exactly {$ask} formative assessment questions, all targeting the SINGLE learning objective below.

SUBJECT: {$subject}
UNIT: {$unitName}
TOPIC: {$topicName}

TARGET LEARNING OBJECTIVE:
{$objectiveText}

APPROVED TEACHER/CURRICULUM MATERIAL:
{$material}
{$avoid}
STRICT RULES:
- Use ONLY facts supported by the approved material above. Do not add outside facts, even if you know them.
- Every question must be objectively auto-gradable.
- Allowed question_type values: mcq, true_false, fill_blank, short_answer.
- Mostly use mcq. For mcq provide exactly four distinct options and exactly one correct answer; correct_answer must be an array holding the EXACT text of that one option.
- For true_false, correct_answer must be ["True"] or ["False"].
- For fill_blank and short_answer, correct_answer lists every accepted answer (short, exact wording from the material).
- difficulty_level must be one of: {$bloom}. Vary it across the set where the objective supports it.
- Do not ask about anything the material does not teach.
- Give a one or two sentence explanation grounded in the material.
PROMPT;

        $schema = [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'question_type' => ['type' => 'string'],
                            'difficulty_level' => ['type' => 'string'],
                            'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'correct_answer' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'question_type', 'correct_answer', 'explanation'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];

        $response = $this->ai->json(
            [
                [
                    'role' => 'system',
                    'content' => 'You create grounded formative questions from supplied curriculum evidence only. Reply with JSON only.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            $schema,
            array_merge([
                'temperature' => 0.35,
                'max_tokens' => min(12000, max(4000, $ask * 650)),
                '_usage_feature' => 'Learner Formative Assessment Generation',
            ], $usage)
        );

        return $this->extractItems($response);
    }

    /** Accept {questions:[...]}, {result:{questions:[...]}} or a bare list. */
    protected function extractItems(array $response): array
    {
        if (isset($response['questions']) && is_array($response['questions'])) {
            return array_values($response['questions']);
        }

        if (isset($response['result']['questions']) && is_array($response['result']['questions'])) {
            return array_values($response['result']['questions']);
        }

        if ($response !== [] && array_keys($response) === range(0, count($response) - 1) && is_array($response[0] ?? null)) {
            return $response;
        }

        return [];
    }

    protected function store(
        LearningPeriod $period,
        Topic $topic,
        LearningObjective $objective,
        array $clean
    ): Question {
        try {
            return Question::create([
                'topic_id' => $topic->id,
                'learning_objective_id' => $objective->id,
                'question_type' => $clean['question_type'],
                'question' => $clean['question'],
                'options' => $clean['options'],
                'correct_answer' => $clean['correct_answer'],
                'marks' => 1,
                'difficulty_level' => $clean['difficulty_level'],
                'is_math' => false,
                'is_chemistry' => false,
                'multiple_answers' => false,
                'is_required' => true,
                'explanation' => $clean['explanation'],
                'created_by' => $period->created_by,
                'source' => self::SOURCE,
                // Attempt content, not Question Bank content.
                'status' => 'draft',
                'is_assessment_eligible' => false,
            ]);
        } catch (QueryException $e) {
            Log::error('Generated formative question could not be stored.', [
                'learning_objective_id' => $objective->id,
                'error' => $e->getMessage(),
            ]);

            // Almost always an out-of-date schema (see the ai_mastery
            // migration). Retrying cannot help, so fail with a clear cause.
            throw new FormativePreparationException(
                'Assessment questions could not be saved. Please tell your teacher or administrator.',
                0,
                $e
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Validation / repair of one AI item
     * ------------------------------------------------------------------ */

    /**
     * @return array{question:string,question_type:string,options:array,correct_answer:array,difficulty_level:string,explanation:?string}|null
     */
    public function normaliseItem(array $item): ?array
    {
        $text = trim(strip_tags((string) ($item['question'] ?? '')));

        if (mb_strlen($text) < self::MIN_QUESTION_CHARS) {
            return null;
        }

        $type = $this->canonicalType($item['question_type'] ?? null);

        if ($type === null) {
            return null;
        }

        $options = $this->stringList($item['options'] ?? []);
        $answers = $this->stringList($item['correct_answer'] ?? []);

        if ($answers === []) {
            return null;
        }

        switch ($type) {
            case 'mcq':
                if (count($options) < 3 || count($options) > 6) {
                    return null;
                }

                $lower = array_map(fn ($o) => mb_strtolower($o), $options);
                if (count(array_unique($lower)) !== count($lower)) {
                    return null;
                }

                $resolved = [];
                foreach ($answers as $answer) {
                    $option = $this->resolveOption($answer, $options);
                    if ($option === null) {
                        return null;
                    }
                    $resolved[$option] = true;
                }

                // Single-answer MCQ only (multiple_answers is false).
                if (count($resolved) !== 1) {
                    return null;
                }

                $answers = [array_key_first($resolved)];
                break;

            case 'true_false':
                $value = mb_strtolower($answers[0]);
                if (in_array($value, ['true', 't', 'yes'], true)) {
                    $answers = ['True'];
                } elseif (in_array($value, ['false', 'f', 'no'], true)) {
                    $answers = ['False'];
                } else {
                    return null;
                }
                $options = ['True', 'False'];
                break;

            default: // fill_blank, short_answer
                $options = [];
                $answers = array_values(array_unique($answers));
                break;
        }

        $explanation = trim((string) ($item['explanation'] ?? ''));

        return [
            'question' => $text,
            'question_type' => $type,
            'options' => array_values($options),
            'correct_answer' => array_values($answers),
            'difficulty_level' => $this->canonicalDifficulty($item['difficulty_level'] ?? null),
            'explanation' => $explanation !== '' ? $explanation : null,
        ];
    }

    protected function canonicalType($value): ?string
    {
        $type = strtolower(trim((string) $value));
        $type = preg_replace('/[\s\-\/]+/', '_', $type);

        $aliases = [
            'multiple_choice' => 'mcq',
            'multiplechoice' => 'mcq',
            'multiple_choice_question' => 'mcq',
            'truefalse' => 'true_false',
            'true_or_false' => 'true_false',
            'boolean' => 'true_false',
            'fill_in_the_blank' => 'fill_blank',
            'fill_in_blank' => 'fill_blank',
            'fillblank' => 'fill_blank',
            'fill_in_the_blanks' => 'fill_blank',
            'short' => 'short_answer',
            'shortanswer' => 'short_answer',
        ];

        $type = $aliases[$type] ?? $type;

        return in_array($type, self::TYPES, true) ? $type : null;
    }

    /** Map free text ("easy", "Apply", "medium") onto the questions.difficulty_level enum. */
    protected function canonicalDifficulty($value): string
    {
        $text = strtolower(trim((string) $value));

        if (in_array($text, self::BLOOM, true)) {
            return $text;
        }

        $map = [
            'remember' => 'remembering', 'recall' => 'remembering', 'easy' => 'remembering',
            'basic' => 'remembering', 'low' => 'remembering',
            'understand' => 'understanding', 'comprehension' => 'understanding',
            'medium' => 'understanding', 'moderate' => 'understanding',
            'apply' => 'applying', 'application' => 'applying',
            'analyse' => 'analyzing', 'analyze' => 'analyzing', 'analysis' => 'analyzing',
            'analysing' => 'analyzing', 'hard' => 'analyzing', 'difficult' => 'analyzing',
            'high' => 'analyzing',
            'evaluate' => 'evaluating', 'evaluation' => 'evaluating',
            'create' => 'creating', 'creation' => 'creating',
        ];

        return $map[$text] ?? 'understanding';
    }

    /** @return string[] trimmed non-empty strings; scalars only */
    protected function stringList($value): array
    {
        if (!is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entry = $entry['text'] ?? $entry['label'] ?? $entry['value'] ?? null;
            }

            if (!is_scalar($entry)) {
                continue;
            }

            $entry = trim((string) $entry);

            if ($entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Find the option an AI answer refers to: exact text, "B" / "B)" / "B. text"
     * or "Option B". Returns the option's own text, or null if there is no match.
     */
    protected function resolveOption(string $answer, array $options): ?string
    {
        $needle = mb_strtolower(trim($answer));

        foreach ($options as $option) {
            if (mb_strtolower($option) === $needle) {
                return $option;
            }
        }

        if (preg_match('/^(?:option\s+)?([A-Fa-f])(?:[\)\.\:\-]\s*(.*))?$/u', trim($answer), $m)) {
            $index = ord(strtoupper($m[1])) - 65;

            if (isset($options[$index])) {
                // "B. text" must also agree with option B's text, if given.
                $rest = trim($m[2] ?? '');
                if ($rest === '' || mb_strtolower($options[$index]) === mb_strtolower($rest)) {
                    return $options[$index];
                }
            }
        }

        // "A) Some text" where the letter is wrong but the text is right.
        if (preg_match('/^[A-Fa-f][\)\.\:]\s*(.+)$/u', trim($answer), $m)) {
            $text = mb_strtolower(trim($m[1]));
            foreach ($options as $option) {
                if (mb_strtolower($option) === $text) {
                    return $option;
                }
            }
        }

        return null;
    }
}
