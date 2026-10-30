<?php

namespace App\Services\AI;

use App\Models\AIUsageLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class AIUsageRecorder
{
    public function success(
        string $provider,
        ?string $model,
        string $operation,
        array $usage = [],
        ?int $latencyMs = null,
        ?string $finishReason = null,
        array $context = []
    ): void {
        $this->store([
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'status' => 'success',
            'http_status' => $context['http_status'] ?? 200,
            'finish_reason' => $finishReason,
            'latency_ms' => $latencyMs,
            'usage' => $usage,
            'context' => $context,
        ]);
    }

    public function failure(
        string $provider,
        ?string $model,
        string $operation,
        Throwable|string $error,
        ?int $latencyMs = null,
        ?int $httpStatus = null,
        array $context = []
    ): void {
        $this->store([
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'status' => 'failed',
            'http_status' => $httpStatus,
            'finish_reason' => null,
            'latency_ms' => $latencyMs,
            'usage' => [],
            'error_message' => $error instanceof Throwable ? $error->getMessage() : (string) $error,
            'context' => $context,
        ]);
    }

    protected function store(array $payload): void
    {
        try {
            $user = auth()->user();
            $request = request();
            $usage = $this->normalizeUsage(
                (string) $payload['provider'],
                (array) ($payload['usage'] ?? [])
            );
            $context = (array) ($payload['context'] ?? []);

            $userId = isset($context['user_id'])
                ? (int) $context['user_id']
                : $user?->id;

            $schoolId = isset($context['school_id'])
                ? (int) $context['school_id']
                : $user?->school_id;

            AIUsageLog::create([
                'user_id' => $userId ?: null,
                'school_id' => $schoolId ?: null,
                'provider' => $payload['provider'],
                'model' => $payload['model'],
                'operation' => $payload['operation'] ?: 'chat',
                'feature' => $context['feature'] ?? $this->featureFromRequest(),
                'route' => $request?->path(),
                'status' => $payload['status'],
                'is_fallback' => (bool) ($context['is_fallback'] ?? false),
                'http_status' => $payload['http_status'],
                'finish_reason' => $payload['finish_reason'],
                'prompt_tokens' => $usage['prompt_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'thinking_tokens' => $usage['thinking_tokens'],
                'cached_tokens' => $usage['cached_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'latency_ms' => $payload['latency_ms'],
                'error_message' => isset($payload['error_message'])
                    ? mb_substr((string) $payload['error_message'], 0, 4000)
                    : null,
                'metadata' => array_filter([
                    'primary_provider' => $context['primary_provider'] ?? null,
                    'role' => $context['role'] ?? null,
                    'tutor_session_id' => $context['tutor_session_id'] ?? null,
                    'group_id' => $context['group_id'] ?? null,
                    'learning_period_id' => $context['learning_period_id'] ?? null,
                    'subject_id' => $context['subject_id'] ?? null,
                    'unit_id' => $context['unit_id'] ?? null,
                    'topic_id' => $context['topic_id'] ?? null,
                    'learning_objective_id' => $context['learning_objective_id'] ?? null,
                    'provider_usage' => !empty($payload['usage']) ? $payload['usage'] : null,
                ], fn ($value) => $value !== null),
            ]);
        } catch (Throwable $e) {
            // Monitoring must never interrupt a learner/teacher AI request.
            Log::warning('Unable to persist AI usage metadata.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function normalizeUsage(string $provider, array $usage): array
    {
        if ($provider === 'gemini') {
            $prompt = (int) ($usage['promptTokenCount'] ?? 0);
            $output = (int) ($usage['candidatesTokenCount'] ?? 0);
            $thinking = (int) ($usage['thoughtsTokenCount'] ?? 0);
            $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);
            $total = (int) ($usage['totalTokenCount'] ?? ($prompt + $output + $thinking));

            return [
                'prompt_tokens' => $prompt,
                'output_tokens' => $output,
                'thinking_tokens' => $thinking,
                'cached_tokens' => $cached,
                'total_tokens' => $total,
            ];
        }

        // OpenAI Responses API uses input_tokens/output_tokens while Groq
        // (OpenAI-compatible Chat Completions) uses prompt/completion tokens.
        $prompt = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $thinking = (int) (
            $usage['output_tokens_details']['reasoning_tokens']
            ?? $usage['completion_tokens_details']['reasoning_tokens']
            ?? 0
        );
        $cached = (int) (
            $usage['input_tokens_details']['cached_tokens']
            ?? $usage['prompt_tokens_details']['cached_tokens']
            ?? 0
        );
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $output));

        return [
            'prompt_tokens' => $prompt,
            'output_tokens' => $output,
            'thinking_tokens' => $thinking,
            'cached_tokens' => $cached,
            'total_tokens' => $total,
        ];
    }

    protected function featureFromRequest(): ?string
    {
        $path = (string) request()?->path();

        return match (true) {
            str_contains($path, 'tutor') => 'AI Tutor',
            str_contains($path, 'curriculum') => 'Curriculum Analysis',
            str_contains($path, 'questionnaire') => 'Questionnaire Import',
            str_contains($path, 'assessment') => 'Assessment',
            str_contains($path, 'question') => 'Question Generation',
            str_contains($path, 'course-material') => 'Course Materials',
            default => $path !== '' ? 'Other AI' : null,
        };
    }
}
