<?php

namespace App\Services\AI;

/** Informational estimate only: never used to deny or delay student requests. */
class AIUsageCost
{
    public function estimate(string $provider, ?string $model, int $input, int $output, int $cached = 0): ?float
    {
        if (!$model) return null;
        $prices = config('ai_usage_pricing.models', []);
        $entry = $prices[strtolower($provider) . '/' . $model] ?? $prices[$provider . '/' . $model] ?? null;
        if (!is_array($entry) || !is_numeric($entry['input'] ?? null) || !is_numeric($entry['output'] ?? null)) return null;
        $inputPrice = max(0, (float) $entry['input']);
        $outputPrice = max(0, (float) $entry['output']);
        $cachedPrice = isset($entry['cached_input']) && is_numeric($entry['cached_input'])
            ? max(0, (float) $entry['cached_input']) : $inputPrice;
        $cached = max(0, min($cached, $input));
        return round(((max(0, $input - $cached) * $inputPrice) + ($cached * $cachedPrice) + (max(0, $output) * $outputPrice)) / 1000000, 8);
    }

    public function forRow(object $row): ?float
    {
        return $this->estimate((string) $row->provider, $row->model,
            (int) ($row->prompt_tokens ?? 0),
            (int) ($row->output_tokens ?? 0) + (strtolower((string) $row->provider) === 'gemini' ? (int) ($row->thinking_tokens ?? 0) : 0),
            (int) ($row->cached_tokens ?? 0));
    }
}
