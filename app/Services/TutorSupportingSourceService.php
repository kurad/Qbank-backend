<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\CourseMaterialSupportingSource;
use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Services\AI\AIGateway;
use Illuminate\Support\Collection;

class TutorSupportingSourceService
{
    public function __construct(
        protected AIGateway $ai
    ) {}

    public function resolve(
        TutorSession $session,
        TutorSessionObjective $sessionObjective,
        array $primarySupport = []
    ): array {
        $session->loadMissing([
            'topic',
            'unit',
            'objectives.learningObjective',
        ]);

        $sessionObjective->loadMissing('learningObjective');

        $objective = $sessionObjective->learningObjective;

        if (!$objective) {
            return [
                'status' => 'unsupported',
                'reason' => 'No learning goal is available for supporting-source retrieval.',
                'source_ids' => [],
                'sources' => collect(),
            ];
        }

        $sources = $this->candidateSources($session);

        if ($sources->isEmpty()) {
            return [
                'status' => 'unsupported',
                'reason' => 'No teacher-provided supporting sources are available for this material.',
                'source_ids' => [],
                'sources' => collect(),
            ];
        }

        $terms = $this->objectiveTerms($sessionObjective);

        $ranked = $sources
            ->map(function ($source) use ($terms) {
                $source->tutor_support_excerpt =
                    $this->relevantExcerpt(
                        (string) $source->cached_content,
                        $terms
                    );

                $source->tutor_support_score =
                    $this->scoreText(
                        $source->tutor_support_excerpt,
                        $terms
                    );

                return $source;
            })
            ->filter(fn ($source) =>
                trim((string) $source->tutor_support_excerpt) !== ''
            )
            ->sortByDesc('tutor_support_score')
            ->take(3)
            ->values();

        if ($ranked->isEmpty()) {
            return [
                'status' => 'unsupported',
                'reason' => 'The teacher-provided sources do not contain enough readable content relevant to this learning goal.',
                'source_ids' => [],
                'sources' => collect(),
            ];
        }

        $context = $ranked
            ->map(function ($source, $index) {
                return sprintf(
                    "SUPPORTING SOURCE %d\nTitle: %s\nURL: %s\nExcerpt:\n%s",
                    $index + 1,
                    $source->cached_title ?: $source->title,
                    $source->url,
                    $source->tutor_support_excerpt
                );
            })
            ->implode("\n\n---\n\n");

        $primaryStatus = strtolower(
            trim((string) ($primarySupport['status'] ?? 'unsupported'))
        );

        $primaryReason = trim(
            (string) ($primarySupport['reason'] ?? '')
        );

        $primaryMissingFocus = implode(
            '; ',
            array_values(array_filter(
                is_array($primarySupport['missing_focus'] ?? null)
                    ? $primarySupport['missing_focus']
                    : []
            ))
        );

        try {
            $result = $this->ai->json([
                [
                    'role' => 'system',
                    'content' => <<<PROMPT
You are deciding whether TEACHER-PROVIDED SUPPORTING SOURCES contain enough
information to safely fill a verified gap in the teacher's primary material.

Use ONLY the supporting-source excerpts below. Do not use general model
knowledge to fill anything missing.

Return supported only when the excerpts contain enough information to teach the
essential learning goal and check it fairly.

Return unsupported when the necessary content is absent, too vague, or only
loosely related.

LEARNING GOAL
Code: {$objective->code}
Goal: {$objective->objective}
Description: {$objective->description}

PRIMARY-MATERIAL COVERAGE RESULT
Status: {$primaryStatus}
Reason: {$primaryReason}
Missing focus: {$primaryMissingFocus}

When the primary material is PARTIAL, judge whether these supporting sources fill the VERIFIED MISSING FOCUS. They do not need to repeat content that the primary material already supports.

TEACHER-PROVIDED SUPPORTING SOURCES
{$context}
PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => 'Evaluate whether these teacher-approved supporting sources can safely support the current learning goal.',
                ],
            ], [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['supported', 'unsupported'],
                    ],
                    'reason' => ['type' => 'string'],
                    'source_numbers' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                    ],
                ],
                'required' => [
                    'status',
                    'reason',
                    'source_numbers',
                ],
            ], [
                'temperature' => 0,
                'max_tokens' => 450,
                '_usage_feature' => 'Tutor Supporting Source Check',
                '_usage_context' => [
                    'student_id' => $session->student_id,
                    'subject_id' => $session->subject_id,
                    'unit_id' => $session->unit_id,
                    'topic_id' => $session->topic_id,
                    'learning_period_id' => $session->learning_period_id,
                    'tutor_session_id' => $session->id,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Tutor supporting-source evaluation failed.', [
                'session_id' => $session->id,
                'learning_objective_id' => $objective->id,
                'message' => $e->getMessage(),
            ]);

            // An AI outage must not become a permanent material gap.
            throw new \RuntimeException(
                'Supporting source verification is temporarily unavailable. Please retry.',
                0,
                $e
            );
        }

        if (($result['status'] ?? null) !== 'supported') {
            return [
                'status' => 'unsupported',
                'reason' => trim((string) ($result['reason'] ?? 'The supporting sources were not sufficient.')),
                'source_ids' => [],
                'sources' => collect(),
            ];
        }

        $numbers = collect($result['source_numbers'] ?? [])
            ->map(fn ($number) => (int) $number)
            ->filter(fn ($number) => $number >= 1 && $number <= $ranked->count())
            ->unique()
            ->values();

        $selected = $numbers->isNotEmpty()
            ? $numbers->map(fn ($number) => $ranked->get($number - 1))->filter()->values()
            : $ranked->take(2)->values();

        return [
            'status' => 'supported',
            'reason' => trim((string) ($result['reason'] ?? 'Teacher-provided supporting sources cover the missing material.')),
            'source_ids' => $selected->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'sources' => $selected,
        ];
    }

    public function sourcesForObjective(
        TutorSessionObjective $sessionObjective
    ): Collection {
        $ids = collect($sessionObjective->supporting_source_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return CourseMaterialSupportingSource::query()
            ->whereIn('id', $ids->all())
            ->where('status', 'ready')
            ->get()
            ->map(function ($source) use ($sessionObjective) {
                $terms = $this->objectiveTerms($sessionObjective);

                $source->tutor_support_excerpt =
                    $this->relevantExcerpt(
                        (string) $source->cached_content,
                        $terms
                    );

                return $source;
            })
            ->filter(fn ($source) =>
                trim((string) $source->tutor_support_excerpt) !== ''
            )
            ->values();
    }

    protected function candidateSources(TutorSession $session): Collection
    {
        $materials = CourseMaterial::query()
            ->where('status', 'approved')
            ->where('subject_id', $session->subject_id)
            ->where(function ($query) use ($session) {
                $query
                    ->where('topic_id', $session->topic_id)
                    ->orWhere(function ($unitQuery) use ($session) {
                        $unitQuery
                            ->where('unit_id', $session->unit_id)
                            ->whereNull('topic_id');
                    });
            })
            ->pluck('id');

        if ($materials->isEmpty()) {
            return collect();
        }

        return CourseMaterialSupportingSource::query()
            ->whereIn('course_material_id', $materials)
            ->where('status', 'ready')
            ->whereNotNull('cached_content')
            ->get();
    }

    protected function objectiveTerms(
        TutorSessionObjective $sessionObjective
    ): Collection {
        $sessionObjective->loadMissing('learningObjective');

        $objective = $sessionObjective->learningObjective;

        $text = trim(implode(' ', array_filter([
            $objective?->code,
            $objective?->objective,
            $objective?->description,
        ])));

        $stop = [
            'the','and','for','with','that','this','from','into','using',
            'use','understand','explain','describe','identify','apply','show',
            'how','what','why','when','where','which','their','about',
        ];

        return collect(
            preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []
        )
            ->map(fn ($term) => preg_replace('/[^\pL\pN]+/u', '', $term))
            ->filter(fn ($term) =>
                mb_strlen((string) $term) >= 3
                && !in_array($term, $stop, true)
            )
            ->unique()
            ->take(30)
            ->values();
    }

    protected function relevantExcerpt(
        string $content,
        Collection $terms
    ): string {
        $content = trim(preg_replace('/\s+/u', ' ', $content));

        if ($content === '') {
            return '';
        }

        $lower = mb_strtolower($content);
        $positions = [];

        foreach ($terms as $term) {
            $offset = mb_strpos($lower, $term);

            if ($offset !== false) {
                $positions[] = (int) $offset;
            }
        }

        if (!$positions) {
            return mb_substr($content, 0, 6000);
        }

        sort($positions);

        $segments = [];

        foreach (array_slice($positions, 0, 6) as $position) {
            $start = max(0, $position - 700);
            $segments[] = mb_substr($content, $start, 1800);
        }

        $excerpt = implode("\n...\n", array_unique($segments));

        return mb_substr($excerpt, 0, 9000);
    }

    protected function scoreText(
        string $text,
        Collection $terms
    ): int {
        $lower = mb_strtolower($text);
        $score = 0;

        foreach ($terms as $term) {
            if (str_contains($lower, $term)) {
                $score += 4;
            }
        }

        return $score;
    }
}
