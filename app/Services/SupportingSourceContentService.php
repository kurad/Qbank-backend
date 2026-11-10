<?php

namespace App\Services;

use App\Models\CourseMaterialSupportingSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SupportingSourceContentService
{
    public function inspect(string $url): array
    {
        $url = trim($url);

        $parts = parse_url($url);

        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
        ) {
            throw ValidationException::withMessages([
                'url' => 'Please provide a valid public HTTP or HTTPS URL.',
            ]);
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw ValidationException::withMessages([
                'url' => 'URLs containing embedded credentials are not allowed.',
            ]);
        }

        $host = strtolower(trim((string) $parts['host']));
        $this->assertPublicHost($host);

        $response = Http::withHeaders([
                'User-Agent' => 'RevisionHub-SupportingSource/1.0',
                'Accept' => 'text/html,text/plain;q=0.9,*/*;q=0.5',
            ])
            ->connectTimeout(4)
            ->timeout(12)
            ->withOptions([
                // Do not follow redirects automatically. A teacher should add
                // the final trusted URL explicitly, which also avoids redirect
                // based SSRF bypasses.
                'allow_redirects' => false,
            ])
            ->get($url);

        if ($response->redirect()) {
            throw ValidationException::withMessages([
                'url' =>
                    'This URL redirects to another location. Please open it in your browser and add the final destination URL instead.',
            ]);
        }

        if (!$response->successful()) {
            throw ValidationException::withMessages([
                'url' =>
                    'RevisionHub could not retrieve this supporting source (HTTP '
                    . $response->status()
                    . ').',
            ]);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (
            !str_contains($contentType, 'text/html')
            && !str_contains($contentType, 'text/plain')
            && !str_contains($contentType, 'application/xhtml+xml')
        ) {
            throw ValidationException::withMessages([
                'url' =>
                    'This source is reachable, but its content type is not currently supported for Tutor retrieval. '
                    . 'Please use a readable web page or plain-text source.',
            ]);
        }

        $body = (string) $response->body();

        if (strlen($body) > 2_000_000) {
            $body = substr($body, 0, 2_000_000);
        }

        $title = null;

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $match)) {
            $title = $this->cleanText($match[1]);
        }

        $text = str_contains($contentType, 'text/plain')
            ? $this->cleanText($body)
            : $this->extractTeachingText($body);

        if (mb_strlen($text) < 120) {
            throw ValidationException::withMessages([
                'url' =>
                    'The page was reachable, but RevisionHub could not extract enough readable teaching content from it.',
            ]);
        }

        // Cached source content is intentionally bounded. During Tutor use we
        // retrieve only objective-relevant excerpts from this cache.
        if (mb_strlen($text) > 250000) {
            $text = mb_substr($text, 0, 250000);
        }

        return [
            'domain' => $host,
            'cached_title' => $title,
            'cached_content' => $text,
            'content_type' => $contentType ?: null,
            'validated_at' => now(),
        ];
    }


    /**
     * Discover a small number of lesson pages within an explicitly approved
     * source's host and path. Never crawl an entire domain or follow redirects.
     * Successful extraction is cached per page; no new DB records are created.
     */
    public function relatedLessons(
        CourseMaterialSupportingSource $source,
        array $terms,
        int $limit = 3
    ): array {
        $limit = min(4, max(0, $limit));
        if ($limit === 0 || $source->status !== 'ready') return [];

        $root = parse_url((string) $source->url);
        if (!is_array($root) || strtolower($root['scheme'] ?? '') !== 'https'
            || empty($root['host']) || !empty($root['user']) || !empty($root['pass'])
            || isset($root['port'])) return [];

        $host = strtolower($root['host']);
        $this->assertPublicHost($host);
        $rootPath = $root['path'] ?? '/';
        // A teacher-approved /sql/ reference authorizes that subsection, not
        // unrelated pages on the same site.
        $scope = str_ends_with($rootPath, '/') ? $rootPath
            : (str_contains(basename($rootPath), '.') ? dirname($rootPath).'/' : rtrim($rootPath, '/').'/');
        if ($scope === '//') $scope = '/';

        $normalizedTerms = array_values(array_unique(array_filter(array_map(
            fn ($v) => mb_strtolower(trim((string) $v)), $terms
        ), fn ($v) => mb_strlen($v) >= 3)));
        if (!$normalizedTerms) return [];

        $linkCacheKey = 'tutor:approved-links:v2:'.hash('sha256', $source->url);
        $links = Cache::remember($linkCacheKey, now()->addHours(12), function () use ($source, $host, $scope) {
            $response = Http::withHeaders([
                'User-Agent' => 'RevisionHub-SupportingSource/1.0',
                'Accept' => 'text/html',
            ])->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->get($source->url);
            if (!$response->successful() || !str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) return [];
            $html = substr((string) $response->body(), 0, 400000);
            preg_match_all('~<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER);
            $output = [];
            foreach (array_slice($matches, 0, 600) as $match) {
                $href = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(preg_replace('/\s+/u', ' ', strip_tags($match[2])));
                if (!$href || str_starts_with($href, '#') || preg_match('~^(?:javascript:|mailto:|data:)~i', $href)) continue;
                $target = $this->approvedLink($href, $source->url, $host, $scope);
                if (!$target || $target === $source->url) continue;
                $output[$target] = ['url' => $target, 'label' => $label];
                if (count($output) >= 300) break;
            }
            return array_values($output);
        });

        // Rank focused tutorial pages above menus, quizzes, and keyword indexes.
        // Long, specific objective terms are much more valuable than generic
        // words such as "select" or "query".
        $scored = [];
        foreach ($links as $link) {
            $path = mb_strtolower(basename(parse_url($link['url'], PHP_URL_PATH) ?: ''));
            $label = mb_strtolower((string) ($link['label'] ?? ''));
            $haystack = str_replace(['_', '-', '.'], ' ', $path).' '.$label;
            $score = 0;
            foreach ($normalizedTerms as $term) {
                if (mb_strlen($term) < 4 || !preg_match('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/u', $haystack)) continue;
                $score += mb_strlen($term) >= 7 ? 14 : 6;
                if (str_contains($path, $term)) $score += 7;
            }
            if ($score === 0) continue;
            if (preg_match('/(?:challenge|exercise|quiz|exam|test)/i', $path.' '.$label)) $score -= 35;
            if (preg_match('/(?:sql_ref_|reference|keywords)/i', $path.' '.$label)) $score -= 18;
            if (preg_match('/^sql_(?!ref_|challenges_)/i', $path)) $score += 8;
            if ($score > 0) $scored[] = ['score' => $score, 'link' => $link];
        }
        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score'])
            ?: strcmp($a['link']['url'], $b['link']['url']));
        // Diversify the bounded selection across distinct subject concepts.
        // A compound objective must not consume every slot on one subtopic.
        $chosen = [];
        $covered = [];
        while (count($chosen) < $limit && $scored) {
            $bestKey = null;
            $bestValue = -INF;
            foreach ($scored as $key => $row) {
                $label = mb_strtolower($row['link']['label'].' '.basename(parse_url($row['link']['url'], PHP_URL_PATH)));
                $normalized = str_replace(['_', '-'], ' ', $label);
                $hits = array_values(array_filter($normalizedTerms, fn ($term) =>
                    mb_strlen($term) >= 4 && str_contains($normalized, $term)
                ));
                $new = count(array_diff($hits, $covered));
                $value = $row['score'] + 35 * $new;
                if ($value > $bestValue) { $bestValue = $value; $bestKey = $key; }
            }
            if ($bestKey === null) break;
            $selected = $scored[$bestKey];
            $chosen[] = $selected;
            $label = mb_strtolower($selected['link']['label'].' '.basename(parse_url($selected['link']['url'], PHP_URL_PATH)));
            $normalized = str_replace(['_', '-'], ' ', $label);
            foreach ($normalizedTerms as $term) {
                if (mb_strlen($term) >= 4 && str_contains($normalized, $term)) $covered[] = $term;
            }
            $covered = array_unique($covered);
            unset($scored[$bestKey]);
        }
        $lessons = [];
        // Keep total outbound fetches bounded even when some pages fail.
        foreach ($chosen as $row) {
            $link = $row['link'];
            try {
                $data = Cache::remember(
                    'tutor:approved-lesson:v2:'.hash('sha256', $link['url']),
                    now()->addDays(7),
                    fn () => $this->inspect($link['url'])
                );
                $lessons[] = [
                    'url' => $link['url'],
                    'title' => $data['cached_title'] ?: $link['label'],
                    'content' => mb_substr((string) $data['cached_content'], 0, 40000),
                ];
            } catch (\Throwable $e) {
                \Log::warning('Approved supporting lesson could not be fetched.', [
                    'source_id' => $source->id, 'url' => $link['url'],
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return $lessons;
    }

    /** Normalize a link without escaping the teacher-approved directory. */
    protected function approvedLink(string $href, string $base, string $host, string $scope): ?string
    {
        if (str_contains($href, '\\') || str_starts_with($href, '//')) return null;
        $baseParts = parse_url($base);
        $parts = parse_url($href);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
        if (!empty($parts['scheme']) && strtolower($parts['scheme']) !== 'https') return null;
        if (isset($parts['host']) && strtolower($parts['host']) !== $host) return null;
        $path = $parts['path'] ?? '';
        if ($path === '') return null;
        if (!str_starts_with($path, '/')) {
            // Directory URLs such as https://www.w3schools.com/sql/ resolve
            // relative href="sql_distinct.asp" within /sql/, not at /.
            // dirname('/sql/') incorrectly returns '/', excluding every
            // valid lesson from the approved /sql/ subtree.
            $basePath = (string) ($baseParts['path'] ?? '/');
            $parent = str_ends_with($basePath, '/')
                ? $basePath
                : rtrim(dirname($basePath), '/').'/';
            $path = rtrim($parent, '/').'/'.$path;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = rawurldecode($segment);
            if ($segment === '..') array_pop($segments);
            elseif ($segment !== '' && $segment !== '.') $segments[] = $segment;
        }
        $path = '/'.implode('/', $segments);
        if (!str_starts_with($path, $scope)) return null;
        if (!preg_match('~\.(?:html?|php|asp|aspx)$~i', $path)) return null;
        return 'https://'.$host.$path;
    }

    /**
     * Extract the instructional article, not the global navigation.
     * The DOM approach is generic (main/article/role=main, then common
     * content containers); the regex fallback is for PHP without ext-dom.
     * Code samples and headings are preserved with line boundaries.
     */
    protected function extractTeachingText(string $html): string
    {
        if (class_exists(\DOMDocument::class) && class_exists(\DOMXPath::class)) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new \DOMXPath($document);
                foreach ($xpath->query('//script|//style|//noscript|//svg|//canvas|//iframe|//nav|//footer|//aside|//form|//header|//*[@role="navigation"]|//*[contains(concat(" ", normalize-space(@class), " "), " sidebar ")]|//*[contains(concat(" ", normalize-space(@class), " "), " advertisement ")]') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $candidates = $xpath->query('//main|//article|//*[@role="main"]|//*[@id="main"]|//*[@id="content"]|//*[contains(concat(" ",normalize-space(@class)," ")," main-content ")]|//*[contains(concat(" ",normalize-space(@class)," ")," article-content ")]');
                $best = null;
                $bestScore = -1;
                foreach ($candidates as $candidate) {
                    $length = mb_strlen(trim($candidate->textContent));
                    if ($length < 140) continue;
                    $examples = $xpath->query('.//pre|.//code', $candidate)->length;
                    $paragraphs = $xpath->query('.//p', $candidate)->length;
                    $headings = $xpath->query('.//h1|.//h2|.//h3', $candidate)->length;
                    $score = min($length, 30000) / 50 + min($examples, 20) * 60 + min($paragraphs, 30) * 12 + min($headings, 15) * 20;
                    if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
                }
                $node = $best ?: $document->documentElement;
                $text = $this->nodeTeachingText($node);
                if (mb_strlen($text) >= 120) return $text;
            } catch (\Throwable $e) {
                \Log::debug('Supporting-source DOM extraction fallback.', ['message' => $e->getMessage()]);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        // Fallback for PHP installations without ext-dom: select a main-like
        // container when possible; otherwise remove common page chrome.
        if (preg_match('~<main\b[^>]*>(.*?)</main>~is', $html, $m)
            || preg_match('~<article\b[^>]*>(.*?)</article>~is', $html, $m)) {
            $html = $m[1];
        } else {
            // Do not parse nested divs using a single non-greedy regex.
            // Strip known navigation blocks, then retain the remaining body.
            $html = preg_replace('~<(nav|header|footer|aside)\b[^>]*>.*?</\1>~is', ' ', $html);
        }
        $html = preg_replace('~<(script|style|noscript|svg|canvas|iframe|form)\b[^>]*>.*?</\1>~is', ' ', $html);
        $html = preg_replace('~</(?:h[1-6]|p|pre|li|tr|div|section|article)>~i', "\n", $html);
        return $this->cleanTeachingText(strip_tags((string) $html));
    }

    protected function nodeTeachingText(\DOMNode $node): string
    {
        // textContent loses boundaries between code, headings and prose.
        $result = '';
        $walk = function (\DOMNode $current) use (&$walk, &$result): void {
            if ($current instanceof \DOMText) {
                $result .= $current->nodeValue;
                return;
            }
            $tag = strtolower($current->nodeName);
            if (in_array($tag, ['br', 'hr'], true)) { $result .= "\n"; return; }
            $block = in_array($tag, ['h1','h2','h3','h4','h5','h6','p','pre','code','li','tr','section','article','div'], true);
            if ($block) $result .= "\n";
            foreach ($current->childNodes as $child) $walk($child);
            if ($block) $result .= "\n";
        };
        $walk($node);
        return $this->cleanTeachingText($result);
    }

    protected function cleanTeachingText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim((string) $text);
    }

    public function refresh(CourseMaterialSupportingSource $source): CourseMaterialSupportingSource
    {
        $data = $this->inspect($source->url);

        $source->fill([
            ...$data,
            'status' => 'ready',
            'last_error' => null,
        ])->save();

        return $source->fresh();
    }

    protected function assertPublicHost(string $host): void
    {
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            throw ValidationException::withMessages([
                'url' => 'Private or local network addresses are not allowed.',
            ]);
        }

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $ipv4 = @gethostbynamel($host) ?: [];
            $ips = array_merge($ips, $ipv4);

            $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
            foreach ($aaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (!$ips) {
            throw ValidationException::withMessages([
                'url' => 'The source domain could not be resolved.',
            ]);
        }

        foreach (array_unique($ips) as $ip) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                throw ValidationException::withMessages([
                    'url' => 'Private, loopback, link-local, and reserved network addresses are not allowed.',
                ]);
            }
        }
    }

    protected function cleanText(string $text): string
    {
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }
}
