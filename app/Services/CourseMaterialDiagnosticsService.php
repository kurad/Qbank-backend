<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\CourseMaterialSupportingSource;
use App\Models\KnowledgeChunk;
use App\Models\LearningObjective;
use App\Services\AI\AIGateway;
use Illuminate\Support\Collection;

class CourseMaterialDiagnosticsService
{
    public function __construct(
        protected AIGateway $ai
    ) {}

    public function inspect(
        CourseMaterial $material,
        ?string $search = null,
        int $chunkLimit = 80
    ): array {
        $material->loadMissing([
            'pages:id,course_material_id,page_number,extracted_text',
            'visuals.page:id,page_number',
            'supportingSources',
        ]);

        $query = KnowledgeChunk::query()
            ->where('course_material_id', $material->id)
            ->with('page:id,page_number')
            ->orderBy('chunk_index');

        $search = trim((string) $search);

        if ($search !== '') {
            $terms = $this->terms($search);

            $chunks = $query
                ->get()
                ->map(function ($chunk) use ($terms, $search) {
                    $chunk->diagnostic_score = $this->score(
                        (string) $chunk->content,
                        $terms,
                        $search
                    );
                    return $chunk;
                })
                ->filter(fn ($chunk) => $chunk->diagnostic_score > 0)
                ->sortByDesc('diagnostic_score')
                ->take($chunkLimit)
                ->values();
        } else {
            $chunks = $query->limit($chunkLimit)->get();
        }

        $chunkRows = $chunks->map(fn ($chunk) => [
            'id' => $chunk->id,
            'chunk_index' => $chunk->chunk_index,
            'page_id' => $chunk->course_material_page_id,
            'page_number' => $chunk->page?->page_number,
            'status' => $chunk->status,
            'content' => $chunk->content,
            'character_count' => $chunk->character_count,
            'token_count' => $chunk->token_count,
            'score' => (int) ($chunk->diagnostic_score ?? 0),
            'metadata' => $chunk->metadata,
        ])->values();

        $visualRows = $material->visuals
            ->filter(function ($visual) use ($search) {
                if ($search === '') return true;

                $haystack = mb_strtolower(trim(implode(' ', array_filter([
                    $visual->caption,
                    $visual->ocr_text,
                    $visual->ai_description,
                    $visual->file_name,
                ]))));

                foreach ($this->terms($search) as $term) {
                    if (str_contains($haystack, $term)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn ($visual) => [
                'id' => $visual->id,
                'page_id' => $visual->course_material_page_id,
                'page_number' => $visual->page?->page_number,
                'type' => $visual->type,
                'file_name' => $visual->file_name,
                'mime_type' => $visual->mime_type,
                'caption' => $visual->caption,
                'ocr_text' => $visual->ocr_text,
                'ai_description' => $visual->ai_description,
                'metadata' => $visual->metadata,
                'preview_url' => "/api/course-materials/{$material->id}/visuals/{$visual->id}/content",
            ])
            ->values();

        $supportRows = $material->supportingSources
            ->map(function ($source) use ($search) {
                $excerpt = $search !== ''
                    ? $this->bestExcerpt((string) $source->cached_content, $this->terms($search))
                    : null;

                return [
                    'id' => $source->id,
                    'title' => $source->cached_title ?: $source->title,
                    'url' => $source->url,
                    'domain' => $source->domain,
                    'status' => $source->status,
                    'content_type' => $source->content_type,
                    'validated_at' => optional($source->validated_at)?->toIso8601String(),
                    'excerpt' => $excerpt,
                ];
            })
            ->values();

        return [
            'material' => [
                'id' => $material->id,
                'title' => $material->title,
                'status' => $material->status,
                'scope' => $material->scope,
                'extraction_status' => $material->extraction_status,
                'visual_extraction_status' => $material->visual_extraction_status,
                'processing_status' => $material->processing_status,
                'page_count' => $material->pages->count(),
                'chunk_count' => KnowledgeChunk::where('course_material_id', $material->id)->count(),
                'visual_count' => $material->visuals->count(),
                'supporting_source_count' => $material->supportingSources->count(),
            ],
            'search' => $search,
            'chunks' => $chunkRows,
            'visuals' => $visualRows,
            'supporting_sources' => $supportRows,
        ];
    }

    public function testRetrieval(
        CourseMaterial $material,
        string $query,
        int $limit = 8
    ): array {
        $query = trim($query);
        $terms = $this->terms($query);

        $chunks = KnowledgeChunk::query()
            ->where('course_material_id', $material->id)
            ->where('status', 'ready')
            ->with('page:id,page_number')
            ->get()
            ->map(function ($chunk) use ($terms, $query) {
                $chunk->diagnostic_score = $this->score(
                    (string) $chunk->content,
                    $terms,
                    $query
                );
                return $chunk;
            })
            ->sortByDesc('diagnostic_score')
            ->take(max(1, min($limit, 20)))
            ->values();

        return [
            'query' => $query,
            'terms' => $terms->values()->all(),
            'results' => $chunks->map(fn ($chunk) => [
                'id' => $chunk->id,
                'chunk_index' => $chunk->chunk_index,
                'page_number' => $chunk->page?->page_number,
                'score' => (int) $chunk->diagnostic_score,
                'content' => $chunk->content,
            ])->all(),
        ];
    }

    public function objectiveSupport(
        CourseMaterial $material,
        LearningObjective $objective
    ): array {
        $queryText = trim(implode(' ', array_filter([
            $objective->code,
            $objective->objective,
            $objective->description,
        ])));

        $primary = $this->testRetrieval($material, $queryText, 8);

        $supporting = CourseMaterialSupportingSource::query()
            ->where('course_material_id', $material->id)
            ->where('status', 'ready')
            ->whereNotNull('cached_content')
            ->get()
            ->map(function ($source) use ($queryText) {
                $terms = $this->terms($queryText);
                $excerpt = $this->bestExcerpt((string) $source->cached_content, $terms);

                return [
                    'id' => $source->id,
                    'title' => $source->cached_title ?: $source->title,
                    'url' => $source->url,
                    'excerpt' => $excerpt,
                    'score' => $this->score($excerpt, $terms, $queryText),
                ];
            })
            ->filter(fn ($row) => trim((string) $row['excerpt']) !== '')
            ->sortByDesc('score')
            ->take(3)
            ->values();

        $primaryContext = collect($primary['results'])
            ->map(fn ($row, $index) => sprintf(
                "PRIMARY CHUNK %d | page=%s | score=%d\n%s",
                $index + 1,
                $row['page_number'] ?? '?',
                $row['score'],
                $row['content']
            ))
            ->implode("\n\n---\n\n");

        $supportContext = $supporting
            ->map(fn ($row, $index) => sprintf(
                "SUPPORT SOURCE %d | %s | score=%d\n%s",
                $index + 1,
                $row['title'],
                $row['score'],
                $row['excerpt']
            ))
            ->implode("\n\n---\n\n");

        $analysis = [
            'primary_status' => empty($primary['results']) ? 'not_retrieved' : 'unknown',
            'primary_reason' => empty($primary['results'])
                ? 'No ready knowledge chunks were retrieved from this material.'
                : 'Primary evidence is available for inspection.',
            'combined_status' => 'unknown',
            'combined_reason' => 'Run-time evidence is available for inspection.',
            'supported_focus' => [],
            'missing_focus' => [],
        ];

        try {
            $result = $this->ai->json([
                [
                    'role' => 'system',
                    'content' => <<<PROMPT
You are a teacher-facing diagnostics assistant for a school AI Tutor.

Evaluate ONLY the evidence supplied below. Do not use outside subject knowledge.
You must distinguish between the PRIMARY MATERIAL and TEACHER-PROVIDED SUPPORTING SOURCES.

LEARNING OBJECTIVE
Code: {$objective->code}
Objective: {$objective->objective}
Description: {$objective->description}

PRIMARY MATERIAL RETRIEVAL
{$primaryContext}

TEACHER-PROVIDED SUPPORTING SOURCES
{$supportContext}

Return:
- primary_status: supported|partial|unsupported|not_retrieved
- primary_reason
- combined_status: supported|partial|unsupported
- combined_reason
- supported_focus
- missing_focus

The combined result is supported when the primary material plus teacher-provided sources together contain enough evidence to teach and assess the full objective fairly.
PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => 'Diagnose this objective using only the supplied evidence.',
                ],
            ], [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'primary_status' => [
                        'type' => 'string',
                        'enum' => ['supported', 'partial', 'unsupported', 'not_retrieved'],
                    ],
                    'primary_reason' => ['type' => 'string'],
                    'combined_status' => [
                        'type' => 'string',
                        'enum' => ['supported', 'partial', 'unsupported'],
                    ],
                    'combined_reason' => ['type' => 'string'],
                    'supported_focus' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'missing_focus' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => [
                    'primary_status',
                    'primary_reason',
                    'combined_status',
                    'combined_reason',
                    'supported_focus',
                    'missing_focus',
                ],
            ], [
                '_usage_feature' => 'Material Diagnostics',
                'temperature' => 0,
                'max_tokens' => 700,
            ]);

            $analysis = array_merge($analysis, $result);
        } catch (\Throwable $e) {
            \Log::warning('Material objective diagnostics AI classification failed.', [
                'course_material_id' => $material->id,
                'learning_objective_id' => $objective->id,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'objective' => [
                'id' => $objective->id,
                'code' => $objective->code,
                'objective' => $objective->objective,
                'description' => $objective->description,
            ],
            'analysis' => $analysis,
            'primary_evidence' => $primary,
            'supporting_source_evidence' => $supporting->all(),
        ];
    }

    protected function terms(string $text): Collection
    {
        $stop = [
            'the','and','for','with','that','this','from','into','using','use',
            'understand','explain','describe','identify','apply','show','how',
            'what','why','when','where','which','their','about','student',
            'should','able','will','can','have','has','are','was','were',
        ];

        $raw = preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($raw)
            ->map(fn ($term) => preg_replace('/[^\pL\pN_]+/u', '', $term))
            ->filter(fn ($term) => mb_strlen($term) >= 3 && !in_array($term, $stop, true))
            ->unique()
            ->values();
    }

    protected function score(string $text, Collection $terms, string $query): int
    {
        $content = mb_strtolower($text);
        $score = 0;

        foreach ($terms as $term) {
            $count = substr_count($content, $term);
            if ($count > 0) {
                $score += min($count, 8) * 4;
            }
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', mb_strtolower($query)));
        if ($normalized !== '' && mb_strlen($normalized) >= 8 && str_contains($content, $normalized)) {
            $score += 30;
        }

        return $score;
    }

    protected function bestExcerpt(string $content, Collection $terms, int $max = 2400): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content));
        if ($content === '') return '';

        if ($terms->isEmpty()) {
            return mb_substr($content, 0, $max);
        }

        $lower = mb_strtolower($content);
        $positions = $terms
            ->map(fn ($term) => mb_strpos($lower, $term))
            ->filter(fn ($position) => $position !== false)
            ->values();

        if ($positions->isEmpty()) return '';

        $start = max(0, (int) $positions->min() - 500);
        return trim(mb_substr($content, $start, $max));
    }
}
