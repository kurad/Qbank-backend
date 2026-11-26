<?php

namespace App\Support;

/**
 * Small, dependency-free helpers for lexical matching of objectives against
 * teaching material (term extraction, passage splitting and ranking).
 *
 * Shared by the curriculum content-sufficiency gate and the objective
 * coverage checker so both rank evidence the same way.
 */
class TextTerms
{
    /**
     * Words that appear in almost every learning objective and therefore say
     * nothing about the subject ("Student should be able to describe ...").
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'their', 'them', 'they',
        'are', 'was', 'were', 'will', 'would', 'should', 'could', 'can', 'able', 'ability',
        'student', 'students', 'learner', 'learners', 'pupil', 'pupils', 'teacher',
        'understand', 'understanding', 'know', 'knowledge', 'learn', 'learning', 'objective',
        'describe', 'explain', 'identify', 'define', 'state', 'list', 'use', 'using', 'used',
        'show', 'give', 'make', 'such', 'also', 'each', 'both', 'between', 'about', 'which',
        'what', 'when', 'where', 'how', 'why', 'who', 'its', 'has', 'have', 'had', 'been',
        'being', 'not', 'any', 'all', 'some', 'more', 'most', 'other', 'than', 'then', 'there',
        'these', 'those', 'one', 'two', 'out', 'may', 'might', 'must', 'own', 'etc', 'including',
        'include', 'includes', 'basic', 'simple', 'key', 'main', 'different', 'various', 'common',
        'topic', 'unit', 'lesson', 'chapter', 'section', 'example', 'examples',
    ];

    /**
     * Distinctive search terms from a piece of text.
     *
     * Keeps short domain terms such as "DNA", "pH" or "H2O" (written with
     * capitals/digits) that a plain "length >= 4" rule would discard.
     *
     * @return string[]
     */
    public static function terms(string $text): array
    {
        $tokens = preg_split('/[^\pL\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach ($tokens as $token) {
            $lower = mb_strtolower($token);
            $length = mb_strlen($lower);

            if (in_array($lower, self::STOPWORDS, true)) {
                continue;
            }

            $looksTechnical = $length >= 2
                && (preg_match('/\d/u', $token) === 1
                    || (mb_strtoupper($token) === $token && $length <= 5)
                    || preg_match('/^\p{Ll}\p{Lu}/u', $token) === 1);

            if ($length >= 4 || ($length === 3 && !is_numeric($lower)) || $looksTechnical) {
                $terms[$lower] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * Lower-case text reduced to letters and digits separated by single
     * spaces. Used to compare an AI "quote" with the source regardless of
     * line breaks, bullets, quote styles or spacing.
     */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * Split source text into passages of roughly $target characters,
     * breaking on blank lines / sentence ends rather than mid-sentence.
     *
     * @return string[]
     */
    public static function passages(string $text, int $target = 1200): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n{2,}/u', $text) ?: [$text];

        $passages = [];
        $current = '';

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            // A single very long block: split it on sentence boundaries.
            if (mb_strlen($block) > $target * 1.5) {
                $sentences = preg_split('/(?<=[.!?])\s+/u', $block) ?: [$block];
            } else {
                $sentences = [$block];
            }

            foreach ($sentences as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) > $target) {
                    $passages[] = $current;
                    $current = '';
                }

                $current .= ($current === '' ? '' : "\n") . $piece;
            }
        }

        if (trim($current) !== '') {
            $passages[] = $current;
        }

        return $passages;
    }

    /**
     * Rank passages against a query and return the best ones that actually
     * share vocabulary with it, in document order.
     *
     * @param  string[] $passages
     * @return array<int, array{text:string, score:int}>
     */
    public static function rank(array $passages, string $query, int $limit = 4): array
    {
        $terms = self::terms($query);

        if (!$terms) {
            return [];
        }

        $scored = [];

        foreach ($passages as $index => $passage) {
            $haystack = mb_strtolower($passage);
            $score = 0;
            $distinct = 0;

            foreach ($terms as $term) {
                $count = mb_substr_count($haystack, $term);

                if ($count > 0) {
                    $distinct++;
                    // Reward breadth of matched terms over repetition of one.
                    $score += 3 + min($count, 3);
                }
            }

            // Require at least 2 distinct terms, or the only term if the
            // objective is described by a single distinctive word.
            if ($distinct >= min(2, count($terms)) && $score > 0) {
                $scored[] = ['index' => $index, 'text' => $passage, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);
        usort($top, fn ($a, $b) => $a['index'] <=> $b['index']);

        return array_map(
            fn ($row) => ['text' => $row['text'], 'score' => $row['score']],
            $top
        );
    }
}
