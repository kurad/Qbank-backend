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
        // Focus retrieval on what the primary coverage check says is missing,
        // not just generic words in the complete learning objective.
        $focusTerms = $this->missingFocusTerms($primarySupport);

        $ranked = $sources
            ->map(function ($source) use ($terms, $focusTerms) {
                $source->tutor_support_excerpt =
                    $this->relevantExcerpt(
                        (string) $source->cached_content,
                        $terms,
                        $focusTerms
                    );

                $source->tutor_support_score =
                    $this->scoreText(
                        $source->tutor_support_excerpt,
                        $terms,
                        $focusTerms
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

            return [
                'status' => 'unsupported',
                'reason' => 'Supporting sources could not be verified at this time.',
                'source_ids' => [],
                'sources' => collect(),
            ];
        }

        \Log::info('Tutor supporting-source coverage decision.', [
            'session_id' => $session->id,
            'objective_id' => $objective->id,
            'candidate_source_ids' => $sources->pluck('id')->all(),
            'ranked_source_ids' => $ranked->pluck('id')->all(),
            'excerpt_lengths' => $ranked->map(fn ($source) => mb_strlen((string) $source->tutor_support_excerpt))->all(),
            'status' => $result['status'] ?? 'invalid',
            'reason' => mb_substr((string) ($result['reason'] ?? ''), 0, 700),
        ]);

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

    /**
     * Add specific missing concepts to the retrieval query. When a primary
     * support result was persisted earlier it may have no missing_focus array,
     * so the assessor's reason is also used. These terms only select passages;
     * they do not determine whether the source actually supports the goal.
     */
    protected function missingFocusTerms(array $primarySupport): Collection
    {
        $focus = $primarySupport['missing_focus'] ?? [];
        $text = implode(' ', is_array($focus) ? $focus : [(string) $focus]);
        $text .= ' ' . (string) ($primarySupport['reason'] ?? '');

        $ignore = [
            'material','materials','source','sources','content','cover',
            'covered','coverage','provide','provides','providing','does',
            'doesnt','enough','sufficient','insufficient','explain',
            'explains','explanation','example','examples','clear','full',
            'goal','objective','syntax','using','used','teaching','teach',
            'assessed','assess','fairly','requires','include','includes',
            'including','with','from','into','have','has','and','the','that',
            'this','not','but','how','for','are','which','without','also',
            'selection','selecting','students','asks','task','tasks',
        ];

        return collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn ($term) => mb_strlen((string) $term) >= 3 && !in_array($term, $ignore, true))
            ->unique()->take(24)->values();
    }

    /**
     * Rank overlapping context windows by objective terms and missing focus.
     * Previously only the FIRST occurrence of each term was used, causing
     * menus and unrelated introductory text to displace relevant lessons.
     */
    protected function relevantExcerpt(
        string $content,
        Collection $terms,
        ?Collection $focusTerms = null
    ): string {
        $content = trim((string) preg_replace('/\s+/u', ' ', $content));
        if ($content === '') {
            return '';
        }

        $length = mb_strlen($content);
        $focusTerms = $focusTerms ?? collect();
        $anchors = $focusTerms->concat($terms)->unique()->filter()->values();
        if ($anchors->isEmpty()) {
            return mb_substr($content, 0, 6000);
        }

        $lower = mb_strtolower($content);
        $windows = [];
        // Scan multiple occurrences, not just the first match. Bound the work
        // to prevent pathological input from a long externally hosted page.
        foreach ($anchors as $term) {
            $offset = 0;
            $seen = 0;
            while ($seen < 25 && ($position = mb_strpos($lower, (string) $term, $offset)) !== false) {
                $start = max(0, (int) $position - 500);
                $start = (int) (floor($start / 300) * 300);
                $windows[$start] = true;
                $offset = $position + max(1, mb_strlen((string) $term));
                $seen++;
            }
        }

        if (!$windows) {
            return mb_substr($content, 0, 6000);
        }

        $scored = [];
        foreach (array_keys($windows) as $start) {
            $section = mb_substr($content, $start, 1600);
            $scored[] = [
                'start' => $start,
                'text' => $section,
                'score' => $this->scoreText($section, $terms, $focusTerms),
            ];
        }

        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['start'] <=> $b['start']));
        $selected = [];
        foreach ($scored as $entry) {
            // Suppress mostly-overlapping windows to preserve evidence from
            // more than one part of the approved source.
            $overlap = false;
            foreach ($selected as $old) {
                if (abs($entry['start'] - $old['start']) < 900) {
                    $overlap = true;
                    break;
                }
            }
            if ($overlap) continue;
            $selected[] = $entry;
            if (count($selected) >= 5) break;
        }

        usort($selected, fn ($a, $b) => $a['start'] <=> $b['start']);
        return mb_substr(implode("\n...\n", array_column($selected, 'text')), 0, 9000);
    }

    protected function scoreText(
        string $text,
        Collection $terms,
        ?Collection $focusTerms = null
    ): int {
        $lower = mb_strtolower($text);
        $score = 0;
        foreach ($terms as $term) {
            if (mb_strpos($lower, (string) $term) !== false) $score += 2;
        }
        foreach (($focusTerms ?? collect()) as $term) {
            if (mb_strpos($lower, (string) $term) !== false) $score += 8;
        }
        return $score;
    }
}
