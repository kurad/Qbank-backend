<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Models\KnowledgeChunk;
use Illuminate\Support\Facades\DB;

class KnowledgeChunkingService
{
    protected int $chunkSize = 3500;
    protected int $overlap = 500;

    public function process(CourseMaterial $material): int
    {
        $material->loadMissing('pages');

        if ($material->pages->isNotEmpty()) {
            return $this->processPages($material);
        }

        return $this->processLegacyText($material);
    }

    protected function processPages(CourseMaterial $material): int
    {
        $records = [];
        $globalIndex = 0;

        foreach ($material->pages as $page) {
            $text = $this->cleanText($page->text ?? '');

            if ($text === '') {
                continue;
            }

            $chunks = $this->splitText($text);

            foreach ($chunks as $pageChunkIndex => $content) {
                $records[] = [
                    'page_id' => $page->id,
                    'page_number' => $page->page_number,
                    'page_chunk_index' => $pageChunkIndex,
                    'chunk_index' => $globalIndex++,
                    'content' => $content,
                ];
            }
        }

        if (empty($records)) {
            throw new \RuntimeException(
                'The course material contains no usable page text.'
            );
        }

        DB::transaction(function () use ($material, $records) {
            $material->knowledgeChunks()->delete();

            foreach ($records as $record) {
                KnowledgeChunk::create([
                    'course_material_id' => $material->id,
                    'course_material_page_id' => $record['page_id'],
                    'subject_id' => $material->subject_id,
                    'unit_id' => $material->unit_id,
                    'topic_id' => $material->topic_id,

                    'chunk_index' => $record['chunk_index'],

                    'content' => $record['content'],

                    'character_count' => mb_strlen(
                        $record['content']
                    ),

                    'token_count' => null,

                    'metadata' => [
                        'source_file' => $material->file_name,
                        'material_title' => $material->title,
                        'scope' => $material->scope,

                        'page_number' => $record['page_number'],

                        'page_chunk_index' =>
                            $record['page_chunk_index'],

                        'source_type' => 'pdf_page',
                    ],

                    'status' => 'ready',
                    'processing_error' => null,
                ]);
            }
        });

        return count($records);
    }

    protected function processLegacyText(CourseMaterial $material): int
    {
        if (empty($material->extracted_text)) {
            throw new \RuntimeException(
                'The course material does not contain extracted text.'
            );
        }

        $text = $this->cleanText($material->extracted_text);

        if ($text === '') {
            throw new \RuntimeException(
                'The course material contains no usable text.'
            );
        }

        $chunks = $this->splitText($text);

        DB::transaction(function () use ($material, $chunks) {
            $material->knowledgeChunks()->delete();

            foreach ($chunks as $index => $content) {
                KnowledgeChunk::create([
                    'course_material_id' => $material->id,
                    'course_material_page_id' => null,
                    'subject_id' => $material->subject_id,
                    'unit_id' => $material->unit_id,
                    'topic_id' => $material->topic_id,

                    'chunk_index' => $index,

                    'content' => $content,

                    'character_count' => mb_strlen($content),

                    'token_count' => null,

                    'metadata' => [
                        'source_file' => $material->file_name,
                        'material_title' => $material->title,
                        'scope' => $material->scope,
                        'source_type' => 'legacy_text',
                    ],

                    'status' => 'ready',
                    'processing_error' => null,
                ]);
            }
        });

        return count($chunks);
    }

    protected function cleanText(string $text): string
    {
        $text = str_replace(
            ["\r\n", "\r"],
            "\n",
            $text
        );

        $text = preg_replace(
            '/[ \t]+/u',
            ' ',
            $text
        ) ?? $text;

        $text = preg_replace(
            "/\n{3,}/u",
            "\n\n",
            $text
        ) ?? $text;

        return trim($text);
    }

    protected function splitText(string $text): array
    {
        $length = mb_strlen($text);

        if ($length <= $this->chunkSize) {
            return [$text];
        }

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $remaining = $length - $start;

            if ($remaining <= $this->chunkSize) {
                $content = trim(
                    mb_substr($text, $start, $remaining)
                );

                if ($content !== '') {
                    $chunks[] = $content;
                }

                break;
            }

            $chunk = mb_substr(
                $text,
                $start,
                $this->chunkSize
            );

            $breakPosition = mb_strrpos(
                $chunk,
                "\n\n"
            );

            if ($breakPosition === false) {
                $breakPosition = mb_strrpos(
                    $chunk,
                    '. '
                );

                if ($breakPosition !== false) {
                    $breakPosition += 1;
                }
            }

            if (
                $breakPosition === false ||
                $breakPosition < ($this->chunkSize * 0.5)
            ) {
                $breakPosition = $this->chunkSize;
            }

            $content = trim(
                mb_substr(
                    $text,
                    $start,
                    $breakPosition
                )
            );

            if ($content !== '') {
                $chunks[] = $content;
            }

            $nextStart =
                $start +
                $breakPosition -
                $this->overlap;

            $start = max(
                $start + 1,
                $nextStart
            );
        }

        return $chunks;
    }
}