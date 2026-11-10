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

        $body = preg_replace('/<(script|style|noscript|svg|canvas|iframe)\b[^>]*>.*?<\/\1>/is', ' ', $body);
        $text = $this->cleanText(strip_tags((string) $body));

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
        $limit = min(3, max(0, $limit));
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

        $scored = [];
        foreach ($links as $link) {
            $haystack = mb_strtolower($link['label'].' '.basename(parse_url($link['url'], PHP_URL_PATH) ?: ''));
            $score = 0;
            foreach ($normalizedTerms as $term) {
                if (mb_strpos($haystack, $term) !== false) $score += mb_strlen($term) >= 5 ? 5 : 2;
            }
            if ($score > 0) $scored[] = ['score' => $score, 'link' => $link];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $lessons = [];
        // Keep total outbound fetches bounded even when some pages fail.
        foreach (array_slice($scored, 0, $limit) as $row) {
            $link = $row['link'];
            try {
                $data = Cache::remember(
                    'tutor:approved-lesson:v1:'.hash('sha256', $link['url']),
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
