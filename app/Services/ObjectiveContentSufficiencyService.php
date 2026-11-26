<?php

namespace App\Services;

use App\Exceptions\CurriculumException;
use App\Services\AI\AIGateway;
use App\Support\TextTerms;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Content-sufficiency gate.
 *
 * An objective may only be created when the teacher's material contains
 * enough to TEACH it (and fairly assess it). This service decides that, in
 * two layers:
 *
 *  1. Deterministic grounding - an AI-proposed objective must quote a real
 *     passage of the source material. Quotes that are not in the material
 *     (hallucinated or heavily reworded) are rejected without trusting the AI.
 *  2. Strict AI judgement - a second, separate call sees ONLY the grounded
 *     evidence for each objective and answers sufficient / insufficient.
 *     A bare mention in a list or heading is insufficient. Any doubt,
 *     missing verdict or malformed answer counts as insufficient (fail
 *     closed); a provider outage raises CurriculumException so nothing is
 *     created on the strength of an unverified guess.
 */
class ObjectiveContentSufficiencyService
{
    /** An excerpt must be at least this long to count as teaching content. */
    public const MIN_EXCERPT_WORDS = 20;

    /** Share of the excerpt's word-runs that must exist in the material. */
    public const MIN_GROUNDED_RATIO = 0.8;

    /**
     * Below this much evidence text there is nothing worth sending to the
     * judge. Deliberately low: it only saves a call when there is effectively
     * no text; whether the text is ENOUGH is the judge's decision.
     */
    public const MIN_EVIDENCE_CHARS = 120;

    /** Evidence sent per objective to the AI judge. */
    private const MAX_EVIDENCE_CHARS = 6000;

    /** Word-run length used for grounding comparison. */
    private const SHINGLE = 5;

    /** Hard cap on evidence per objective at judging time. */
    private const MAX_JUDGE_EVIDENCE_CHARS = 12000;

    /** Objectives judged per AI call. */
    private const BATCH_SIZE = 8;

    public function __construct(protected AIGateway $ai) {}

    // ---------------------------------------------------------------------
    // Source index
    // ---------------------------------------------------------------------

    /**
     * Pre-compute what is needed to ground excerpts and retrieve passages
     * from a piece of source text. Build once per analysis.
     *
     * @return array{shingles:array<string,bool>, passages:string[]}
     */
    public function index(string $sourceText): array
    {
        $words = explode(' ', TextTerms::normalise($sourceText));
        $shingles = [];
        $count = count($words);

        for ($i = 0; $i + self::SHINGLE <= $count; $i++) {
            $shingles[implode(' ', array_slice($words, $i, self::SHINGLE))] = true;
        }

        return [
            'shingles' => $shingles,
            'passages' => TextTerms::passages($sourceText),
        ];
    }

    /**
     * Is the excerpt genuinely present in the source?
     *
     * @return array{ok:bool, reason:?string}
     */
    public function checkExcerpt(array $index, ?string $excerpt): array
    {
        $excerpt = trim((string) $excerpt);

        if ($excerpt === '') {
            return ['ok' => false, 'reason' => 'No supporting passage from the material was provided.'];
        }

        $words = array_values(array_filter(
            explode(' ', TextTerms::normalise($excerpt)),
            fn ($w) => $w !== ''
        ));

        if (count($words) < self::MIN_EXCERPT_WORDS) {
            return ['ok' => false, 'reason' => 'The supporting passage is too short to teach from.'];
        }

        $total = 0;
        $found = 0;

        for ($i = 0; $i + self::SHINGLE <= count($words); $i++) {
            $total++;

            if (isset($index['shingles'][implode(' ', array_slice($words, $i, self::SHINGLE))])) {
                $found++;
            }
        }

        if ($total === 0 || ($found / $total) < self::MIN_GROUNDED_RATIO) {
            return ['ok' => false, 'reason' => 'The supporting passage could not be found in the uploaded material.'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Evidence text for one objective: its grounded excerpt (if any) plus the
     * best-matching passages of the material.
     */
    public function evidenceFor(array $index, string $objectiveText, ?string $description, ?string $groundedExcerpt = null): string
    {
        $parts = [];
        $excerpt = trim((string) $groundedExcerpt);
        $excerptKey = $excerpt !== '' ? TextTerms::normalise($excerpt) : '';
        $excerptCovered = false;

        foreach (TextTerms::rank($index['passages'], $objectiveText . ' ' . $description, 4) as $row) {
            // A retrieved passage that contains the quote IS the quote plus
            // its surrounding context (examples, the rule after the
            // definition...), so it replaces the bare quote rather than being
            // discarded as a duplicate of it.
            if ($excerptKey !== '' && str_contains(TextTerms::normalise($row['text']), $excerptKey)) {
                $excerptCovered = true;
            }

            $parts[] = $row['text'];
        }

        if ($excerpt !== '' && !$excerptCovered) {
            array_unshift($parts, $excerpt);
        }

        return mb_substr(implode("\n\n---\n\n", $parts), 0, self::MAX_EVIDENCE_CHARS);
    }

    // ---------------------------------------------------------------------
    // Verdicts
    // ---------------------------------------------------------------------

    /**
     * Judge whether the evidence is enough to teach each objective.
     *
     * @param  array<int|string, array{topic?:string, objective:string, description?:?string, evidence:string}> $items keyed by caller-chosen key
     * @return array<int|string, array{sufficient:bool, reason:string, missing:string[]}>
     *
     * @throws CurriculumException when the AI judge cannot be reached or
     *         keeps returning unusable output (nothing may be accepted then).
     */
    public function assess(array $items): array
    {
        $verdicts = [];
        $toJudge = [];

        foreach ($items as $key => $item) {
            if (mb_strlen(trim((string) ($item['evidence'] ?? ''))) < self::MIN_EVIDENCE_CHARS) {
                $verdicts[$key] = [
                    'sufficient' => false,
                    'reason' => 'The material contains too little text about this objective to teach it.',
                    'missing' => [(string) $item['objective']],
                ];
                continue;
            }

            $toJudge[$key] = $item;
        }

        foreach (array_chunk($toJudge, self::BATCH_SIZE, true) as $batch) {
            $verdicts += $this->judgeBatch($batch);
        }

        return $verdicts;
    }

    /**
     * Convenience wrapper for a single objective.
     *
     * @return array{sufficient:bool, reason:string, missing:string[]}
     */
    public function assessOne(string $topic, string $objective, ?string $description, string $evidence): array
    {
        return $this->assess([
            0 => [
                'topic' => $topic,
                'objective' => $objective,
                'description' => $description,
                'evidence' => $evidence,
            ],
        ])[0];
    }

    /**
     * @param  array<int|string, array> $batch
     * @return array<int|string, array{sufficient:bool, reason:string, missing:string[]}>
     */
    protected function judgeBatch(array $batch): array
    {
        // Short numeric ids keep the prompt robust; map back to caller keys.
        $ids = array_keys($batch);
        $blocks = [];

        foreach ($ids as $position => $key) {
            $item = $batch[$key];
            $blocks[] = implode("\n", [
                'OBJECTIVE ID: ' . $position,
                'Topic: ' . ($item['topic'] ?? ''),
                'Objective: ' . $item['objective'],
                'Description: ' . ($item['description'] ?? ''),
                "EVIDENCE FROM THE TEACHER'S MATERIAL:",
                mb_substr((string) $item['evidence'], 0, self::MAX_JUDGE_EVIDENCE_CHARS),
            ]);
        }

        $messages = [
            [
                'role' => 'system',
                'content' =>
                    'You are a strict curriculum quality reviewer. For each learning objective decide whether the ' .
                    'supplied teacher material contains ENOUGH content for a teacher to actually teach the objective ' .
                    'and for students to be fairly assessed on it. ' .
                    'Judge each objective ONLY from its own evidence block. Do not use outside knowledge and do not ' .
                    'fill gaps yourself. ' .
                    '"sufficient" requires BOTH: (a) the central concept or skill is actually explained or ' .
                    'demonstrated in the evidence (a definition, explanation, rule, procedure or worked example), ' .
                    'not merely named in a heading, list or one-line mention; and (b) every essential part that the ' .
                    'objective names is covered. If the objective is broader than the evidence, or only part of it ' .
                    'is covered, answer "insufficient". When in doubt, answer "insufficient". ' .
                    'Return ONE JSON object of exactly this shape and nothing else: ' .
                    '{"results":[{"id":<integer objective id>,"status":"sufficient" or "insufficient",' .
                    '"reason":"one short sentence","missing":["short phrase", ...]}]} ' .
                    'Include exactly one entry per objective id. "missing" is an empty array when sufficient. ' .
                    'No markdown, no extra keys.',
            ],
            [
                'role' => 'user',
                'content' => implode("\n\n=====\n\n", $blocks),
            ],
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => ['sufficient', 'insufficient']],
                            'reason' => ['type' => 'string'],
                            'missing' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['id', 'status', 'reason', 'missing'],
                    ],
                ],
            ],
            'required' => ['results'],
        ];

        $options = [
            'temperature' => 0,
            'max_tokens' => 2500,
            // The word "curriculum" routes this to the curriculum AI provider order.
            '_usage_feature' => 'Curriculum Content Sufficiency Check',
        ];

        $parsed = null;

        for ($attempt = 1; $attempt <= 2 && $parsed === null; $attempt++) {
            try {
                $raw = $this->ai->json($messages, $schema, $options);
            } catch (Throwable $e) {
                Log::warning('Content sufficiency check: AI call failed.', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt === 2) {
                    throw new CurriculumException(
                        'The AI service could not verify that the material contains enough content to teach these ' .
                        'objectives, so none were created. Please try again in a moment.'
                    );
                }

                continue;
            }

            $parsed = $this->parseResults($raw, count($ids));

            if ($parsed === null) {
                Log::warning('Content sufficiency check: malformed AI result; retrying once.', [
                    'attempt' => $attempt,
                    'keys' => is_array($raw) ? array_keys($raw) : [],
                ]);

                $messages[] = [
                    'role' => 'user',
                    'content' =>
                        'Your answer must be ONE JSON object {"results":[{"id":int,"status":"sufficient"|"insufficient",' .
                        '"reason":string,"missing":[string]}]} with one entry per objective id. Return only that object.',
                ];
            }
        }

        if ($parsed === null) {
            throw new CurriculumException(
                'The AI service returned an unusable answer while verifying the content, so no objectives were ' .
                'created. Please try again.'
            );
        }

        $verdicts = [];

        foreach ($ids as $position => $key) {
            $row = $parsed[$position] ?? null;

            // Fail closed: no verdict for an objective means it is not accepted.
            $verdicts[$key] = [
                'sufficient' => $row !== null && $row['status'] === 'sufficient',
                'reason' => $row['reason'] ?? 'No verdict was returned for this objective.',
                'missing' => $row['missing'] ?? [],
            ];
        }

        return $verdicts;
    }

    /**
     * @return array<int, array{status:string, reason:string, missing:string[]}>|null
     */
    protected function parseResults(mixed $raw, int $expected): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $rows = $raw['results'] ?? (array_is_list($raw) ? $raw : null);

        if (!is_array($rows)) {
            return null;
        }

        $parsed = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id'])) {
                continue;
            }

            $status = strtolower(trim((string) ($row['status'] ?? '')));

            if (!in_array($status, ['sufficient', 'insufficient'], true)) {
                continue;
            }

            $id = (int) $row['id'];

            if ($id < 0 || $id >= $expected) {
                continue;
            }

            $missing = $row['missing'] ?? [];
            $missing = is_array($missing)
                ? array_values(array_filter(array_map(
                    fn ($m) => is_string($m) ? trim($m) : '',
                    $missing
                )))
                : [];

            $parsed[$id] = [
                'status' => $status,
                'reason' => trim((string) ($row['reason'] ?? '')),
                'missing' => $missing,
            ];
        }

        // Nothing usable at all is a malformed answer, not "all insufficient".
        return $parsed === [] ? null : $parsed;
    }
}
