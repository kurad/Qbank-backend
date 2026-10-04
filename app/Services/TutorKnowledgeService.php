<?php

namespace App\Services;

use App\Models\KnowledgeChunk;
use App\Models\TutorSession;
use Illuminate\Support\Collection;

class TutorKnowledgeService
{
    public function retrieve(
        TutorSession $session,
        string $query,
        int $limit = 6
    ): Collection {
        $session->loadMissing([
            'unit',
            'topic.learningObjectives',
            'objectives.learningObjective',
        ]);

        $searchText = $this->buildSearchText($session, $query);
        $terms = $this->extractTerms($searchText)->take(30);

        $baseQuery = KnowledgeChunk::query()
            ->where('status', 'ready')
            ->where('subject_id', $session->subject_id)
            ->whereHas('courseMaterial', function ($q) {
                $q->where('status', 'approved')
                    ->where('processing_status', 'completed');
            })
            ->with(['courseMaterial:id,title,subject_id,unit_id,topic_id,scope']);

        /*
         * Keep tutoring knowledge inside the narrowest approved curriculum
         * scope available for the current session. Mixing topic-, unit-, and
         * subject-level chunks in one pool can cause valid but broader subject
         * knowledge to leak into a lesson that the teacher has not introduced.
         *
         * Priority:
         *   1. current topic
         *   2. current unit (only when no topic chunks exist)
         *   3. subject/global approved material (last resort only)
         */
        $topicChunks = (clone $baseQuery)
            ->where('topic_id', $session->topic_id)
            ->get();

        if ($topicChunks->isNotEmpty()) {
            $chunks = $topicChunks;
        } else {
            $unitChunks = (clone $baseQuery)
                ->where('unit_id', $session->unit_id)
                ->whereNull('topic_id')
                ->get();

            if ($unitChunks->isNotEmpty()) {
                $chunks = $unitChunks;
            } else {
                $chunks = (clone $baseQuery)
                    ->whereNull('topic_id')
                    ->whereNull('unit_id')
                    ->get();
            }
        }

        if ($chunks->isEmpty()) {
            return collect();
        }

        return $chunks
            ->map(function ($chunk) use ($session, $terms, $searchText) {
                $chunk->tutor_relevance_score = $this->scoreChunk(
                    $chunk,
                    $session,
                    $terms,
                    $searchText
                );
                $chunk->tutor_material_scope = $this->materialScope($chunk, $session);
                return $chunk;
            })
            ->sortByDesc('tutor_relevance_score')
            ->take(max(1, $limit))
            ->values();
    }

    protected function buildSearchText(TutorSession $session, string $query): string
    {
        $parts = [];

        if (!empty($query)) {
            $parts[] = $query;
        }
        if ($session->topic?->topic_name) {
            $parts[] = $session->topic->topic_name;
        }
        if ($session->unit?->name) {
            $parts[] = $session->unit->name;
        }

        $objectives = $session->objectives
            ->sortBy('objective_order')
            ->filter(fn ($item) => !in_array(
                strtolower((string) $item->status),
                ['completed', 'mastered'],
                true
            ))
            ->take(2)
            ->map(fn ($item) => $item->learningObjective?->objective)
            ->filter()
            ->values()
            ->all();

        return trim(implode(' ', array_merge($parts, $objectives)));
    }

    protected function extractTerms(string $text): Collection
    {
        $terms = preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($terms)
            ->map(fn ($term) => preg_replace('/[^\pL\pN]+/u', '', $term))
            ->filter(fn ($term) => mb_strlen($term) >= 3)
            ->unique()
            ->values();
    }

    protected function scoreChunk(
        $chunk,
        TutorSession $session,
        Collection $terms,
        string $searchText
    ): int {
        $content = mb_strtolower((string) $chunk->content);
        $score = 0;

        if ((int) $chunk->topic_id === (int) $session->topic_id) {
            $score += 100;
        }
        if (!empty($chunk->unit_id) && (int) $chunk->unit_id === (int) $session->unit_id) {
            $score += 50;
        }
        if (is_null($chunk->topic_id) && is_null($chunk->unit_id)) {
            $score += 15;
        }

        foreach ($terms as $term) {
            $occurrences = substr_count($content, $term);
            if ($occurrences > 0) {
                $score += min($occurrences, 6) * 3;
            }
        }

        $normalizedSearch = trim(preg_replace('/\s+/u', ' ', mb_strtolower($searchText)));
        if ($normalizedSearch !== '' && mb_strlen($normalizedSearch) >= 8 && str_contains($content, $normalizedSearch)) {
            $score += 25;
        }

        $material = $chunk->courseMaterial;
        if ($material) {
            if ((int) $material->topic_id === (int) $session->topic_id) {
                $score += 30;
            }
            if (!empty($material->unit_id) && (int) $material->unit_id === (int) $session->unit_id) {
                $score += 15;
            }
        }

        return $score;
    }

    protected function materialScope($chunk, TutorSession $session): string
    {
        if ((int) $chunk->topic_id === (int) $session->topic_id) {
            return 'topic';
        }
        if (!empty($chunk->unit_id) && (int) $chunk->unit_id === (int) $session->unit_id) {
            return 'unit';
        }
        return 'subject';
    }
}