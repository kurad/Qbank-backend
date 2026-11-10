<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Centrally managed ordering. An absent override inherits the global list. */
class AIGatewayRouting
{
    public const PROVIDERS = ['openai', 'gemini', 'groq'];
    public const FEATURES = [
        'tutor' => 'AI Tutor',
        'question_generation' => 'Question Generation',
        'answer_evaluation' => 'Answer Evaluation',
        'curriculum_analysis' => 'Curriculum Analysis',
        'other' => 'Other AI Features',
    ];

    public function configuredDefault(): array
    {
        $primary = (string) config('services.ai.provider', 'openai');
        $others = config('services.ai.fallback_providers', ['gemini', 'groq']);
        $raw = array_merge([$primary], is_array($others) ? $others : explode(',', (string) $others));
        $order = $this->clean($raw);
        return $order ?: self::PROVIDERS;
    }

    public function clean(array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($key) => is_string($key) ? strtolower(trim($key)) : '',
            $keys
        ), fn ($key) => in_array($key, self::PROVIDERS, true))));
    }

    public function featureFor(?string $feature): string
    {
        $text = strtolower(trim((string) $feature));
        if ($text === '') return 'other';
        if (array_key_exists($text, self::FEATURES)) return $text;
        $text = str_replace('_', ' ', $text);
        if (str_contains($text, 'curriculum')) return 'curriculum_analysis';
        if (str_contains($text, 'question generat') || str_contains($text, 'questionnaire')) return 'question_generation';
        if (str_contains($text, 'evaluat') || str_contains($text, 'marking') || str_contains($text, 'grading') || str_contains($text, 'review')) return 'answer_evaluation';
        if (str_contains($text, 'tutor') || str_contains($text, 'formative') || str_contains($text, 'learning') || str_contains($text, 'supporting source')) return 'tutor';
        return 'other';
    }

    public function rows(): array
    {
        return Cache::remember('revisionhub.ai_gateway_routes.v1', 60, function () {
            if (!Schema::hasTable('ai_gateway_routes')) return [];
            return DB::table('ai_gateway_routes')->get(['feature', 'providers'])->mapWithKeys(
                function ($row) {
                    $raw = json_decode($row->providers, true);
                    return [$row->feature => is_array($raw) ? $this->clean($raw) : []];
                }
            )->all();
        });
    }

    public function order(?string $usageFeature = null): array
    {
        $rows = $this->rows();
        $feature = $this->featureFor($usageFeature);
        return $rows[$feature] ?? $rows['global'] ?? $this->configuredDefault();
    }

    public function resetCache(): void
    {
        Cache::forget('revisionhub.ai_gateway_routes.v1');
    }
}
