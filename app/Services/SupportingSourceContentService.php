<?php

namespace App\Services;

use App\Models\CourseMaterialSupportingSource;
use Illuminate\Support\Facades\Http;
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
