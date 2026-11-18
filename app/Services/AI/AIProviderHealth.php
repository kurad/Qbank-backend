<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shared, passive provider circuit breaker; never changes configured provider order. */
class AIProviderHealth
{
    private const PREFIX = 'revisionhub.ai_provider_health.v1.';

    public function state(string $provider): ?array
    {
        try {
            $value = Cache::get(self::PREFIX . $provider);
            return is_array($value) ? $value : null;
        } catch (Throwable $e) {
            Log::warning('Provider health cache unavailable; allowing normal routing.');
            return null;
        }
    }

    public function available(string $provider): bool
    {
        return $this->state($provider) === null;
    }

    public function recovered(string $provider): void
    {
        try { Cache::forget(self::PREFIX . $provider); }
        catch (Throwable $e) { Log::warning('Provider health cache clear failed.'); }
    }

    public function failed(string $provider, Throwable $error): void
    {
        $message = strtolower($error->getMessage());
        // Never trip a provider for malformed JSON, app configuration, or model output problems.
        $billing = preg_match('/(exceeded (its|the|your) (spending|budget)|spending (cap|limit)|billing (limit|quota)|insufficient (credits|funds|balance)|credit balance|quota.*(billing|spend)|payment required|resource_exhausted.*billing)/i', $message);
        $rate = preg_match('/(rate.?limit|too many requests|requests per minute|tokens per minute|\b429\b|quota exceeded|resource.exhausted)/i', $message);
        if (!$billing && !$rate) return;
        $seconds = $billing
            ? max(60, (int) config('ai_usage_pricing.billing_cooldown_seconds', 300))
            : max(10, (int) config('ai_usage_pricing.rate_cooldown_seconds', 45));
        $seconds = min($seconds, 3600);
        try {
            Cache::put(self::PREFIX . $provider, [
                'reason' => $billing ? 'spending_limit' : 'rate_limit',
                'until' => now()->addSeconds($seconds)->toIso8601String(),
                'seconds' => $seconds,
            ], $seconds);
        } catch (Throwable $e) {
            Log::warning('Provider health cache write failed; fallback routing is still enabled.');
        }
        // No message/body/key is stored in the cache or exposed through admin response.
        Log::warning('AI provider temporarily paused after capacity failure.', [
            'provider' => $provider, 'reason' => $billing ? 'spending_limit' : 'rate_limit', 'seconds' => $seconds,
        ]);
    }

    public function all(): array
    {
        return collect(AIGatewayRouting::PROVIDERS)->mapWithKeys(fn ($p) => [
            $p => ['state' => $this->available($p) ? 'available' : 'cooldown'] + ($this->state($p) ?? []),
        ])->all();
    }
}
