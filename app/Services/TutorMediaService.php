<?php

namespace App\Services;

use App\Models\CourseMaterialVisual;
use App\Models\TutorSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class TutorMediaService
{
    public function __construct(
        protected TutorKnowledgeService $knowledge
    ) {}

    /**
     * Prepare an assistant teaching response for storage.
     *
     * - removes hidden tutor-graph blocks from visible text;
     * - validates graph payloads;
     * - finds teacher-provided visuals on the same pages as relevant chunks;
     * - returns message metadata without exposing private storage paths.
     */
    public function prepare(
        TutorSession $session,
        string $content,
        ?Collection $chunks = null,
        int $visualLimit = 2
    ): array {
        [$cleanContent, $graphs] = $this->extractGraphBlocks($content);

        $chunks ??= $this->knowledge->retrieve(
            $session,
            $cleanContent,
            6
        );

        $visuals = $this->visualsForChunks(
            $chunks,
            $visualLimit
        );

        $media = [];

        if (!empty($visuals)) {
            $media['visuals'] = $visuals;
        }

        if (!empty($graphs)) {
            $media['graphs'] = $graphs;
        }

        return [
            'content' => trim($cleanContent),
            'metadata' => empty($media)
                ? null
                : ['media' => $media],
        ];
    }

    protected function visualsForChunks(
        Collection $chunks,
        int $limit
    ): array {
        $pageIds = $chunks
            ->pluck('course_material_page_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($pageIds->isEmpty()) {
            return [];
        }

        $pageRank = [];

        foreach ($pageIds as $index => $pageId) {
            $pageRank[$pageId] = $index;
        }

        return CourseMaterialVisual::query()
            ->whereIn('course_material_page_id', $pageIds->all())
            ->where('type', '!=', 'page_snapshot')
            ->with([
                'page:id,page_number',
                'courseMaterial:id,title,status,processing_status',
            ])
            ->orderBy('sort_order')
            ->get()
            ->filter(function (CourseMaterialVisual $visual) {
                if (!$visual->courseMaterial) {
                    return false;
                }

                if ($visual->courseMaterial->status !== 'approved') {
                    return false;
                }

                if ($visual->courseMaterial->processing_status !== 'completed') {
                    return false;
                }

                return Storage::disk('local')->exists(
                    $visual->file_path
                );
            })
            ->sortBy(function (CourseMaterialVisual $visual) use ($pageRank) {
                $pageId = (int) $visual->course_material_page_id;

                return sprintf(
                    '%06d-%06d',
                    $pageRank[$pageId] ?? 999999,
                    (int) ($visual->sort_order ?? 0)
                );
            })
            ->take(max(0, $limit))
            ->values()
            ->map(function (CourseMaterialVisual $visual) {
                return [
                    'id' => $visual->id,
                    'type' => $visual->type,
                    'caption' => $visual->caption,
                    'page_number' => $visual->page?->page_number,
                    'mime_type' => $visual->mime_type,
                ];
            })
            ->all();
    }

    /**
     * The AI may include one or more hidden graph blocks:
     *
     * ```tutor-graph
     * {"title":"...","x_label":"...","y_label":"...","series":[...]}
     * ```
     *
     * These blocks are removed from visible text and stored as structured media.
     */
    protected function extractGraphBlocks(string $content): array
    {
        $graphs = [];

        $clean = preg_replace_callback(
            '/```tutor-graph\s*([\s\S]*?)```/i',
            function (array $matches) use (&$graphs) {
                $decoded = json_decode(
                    trim($matches[1] ?? ''),
                    true
                );

                $graph = $this->normalizeGraph($decoded);

                if ($graph !== null) {
                    $graphs[] = $graph;
                }

                return '';
            },
            $content
        );

        $clean = preg_replace("/\n{3,}/", "\n\n", (string) $clean);

        return [trim((string) $clean), $graphs];
    }

    protected function normalizeGraph(mixed $graph): ?array
    {
        if (!is_array($graph)) {
            return null;
        }

        $title = trim((string) ($graph['title'] ?? 'Graph'));
        $xLabel = trim((string) ($graph['x_label'] ?? 'x'));
        $yLabel = trim((string) ($graph['y_label'] ?? 'y'));
        $seriesInput = $graph['series'] ?? null;

        if (!is_array($seriesInput) || empty($seriesInput)) {
            return null;
        }

        $series = [];

        foreach (array_slice($seriesInput, 0, 3) as $seriesItem) {
            if (!is_array($seriesItem)) {
                continue;
            }

            $label = trim((string) ($seriesItem['label'] ?? 'Series'));
            $pointsInput = $seriesItem['points'] ?? null;

            if (!is_array($pointsInput)) {
                continue;
            }

            $points = [];

            foreach (array_slice($pointsInput, 0, 80) as $point) {
                if (!is_array($point)) {
                    continue;
                }

                $x = $point['x'] ?? null;
                $y = $point['y'] ?? null;

                if (!is_numeric($x) || !is_numeric($y)) {
                    continue;
                }

                $x = (float) $x;
                $y = (float) $y;

                if (!is_finite($x) || !is_finite($y)) {
                    continue;
                }

                $points[] = [
                    'x' => $x,
                    'y' => $y,
                ];
            }

            if (count($points) < 2) {
                continue;
            }

            $series[] = [
                'label' => mb_substr($label ?: 'Series', 0, 80),
                'points' => $points,
            ];
        }

        if (empty($series)) {
            return null;
        }

        return [
            'title' => mb_substr($title ?: 'Graph', 0, 120),
            'x_label' => mb_substr($xLabel ?: 'x', 0, 50),
            'y_label' => mb_substr($yLabel ?: 'y', 0, 50),
            'series' => $series,
        ];
    }
}
