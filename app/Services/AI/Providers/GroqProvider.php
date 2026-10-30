<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AIUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GroqProvider
{
    protected string $url = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(protected AIUsageRecorder $usageRecorder)
    {
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? config('services.groq.model', 'openai/gpt-oss-120b');
        $operation = $options['_usage_operation'] ?? 'chat';
        $context = $this->usageContext($options);
        $startedAt = microtime(true);
        $httpStatus = null;

        try {
            $apiKey = config('services.groq.key');
            if (!$apiKey) {
                throw new RuntimeException('Groq API key is not configured.');
            }

            $payload = array_merge([
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => 4096,
            ], $this->sanitizeOptions($options));
            $payload['model'] = $model;

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(120)
                ->post($this->url, $payload);

            $httpStatus = $response->status();

            if ($response->failed()) {
                $json = $response->json();
                Log::error('Groq provider request failed.', [
                    'model' => $model,
                    'status' => $httpStatus,
                    'body' => $response->body(),
                    'json' => $json,
                ]);

                $message = 'Unknown Groq provider error.';
                if (is_array($json) && isset($json['error'])) {
                    $message = is_array($json['error'])
                        ? ($json['error']['message'] ?? $json['error']['code'] ?? json_encode($json['error']))
                        : (string) $json['error'];
                }

                throw new RuntimeException('Groq provider error: ' . $message);
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new RuntimeException('Groq returned an invalid response.');
            }

            $actualModel = $data['model'] ?? $model;
            $finishReason = $data['choices'][0]['finish_reason'] ?? null;
            $usage = $data['usage'] ?? [];

            Log::info('Groq response received.', [
                'model' => $actualModel,
                'finish_reason' => $finishReason,
                'usage' => $usage ?: null,
            ]);

            $this->usageRecorder->success(
                'groq',
                $actualModel,
                $operation,
                is_array($usage) ? $usage : [],
                $this->latencyMs($startedAt),
                $finishReason,
                $context + ['http_status' => $httpStatus]
            );

            return $data;
        } catch (Throwable $e) {
            $this->usageRecorder->failure(
                'groq',
                $model,
                $operation,
                $e,
                $this->latencyMs($startedAt),
                $httpStatus,
                $context
            );
            Log::error('Groq service request failed.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function text(array $messages, array $options = []): string
    {
        $options['_usage_operation'] = $options['_usage_operation'] ?? 'text';
        $response = $this->chat($messages, $options);
        $text = $response['choices'][0]['message']['content'] ?? null;

        if (!$text) {
            throw new RuntimeException('Groq returned an empty response.');
        }

        return trim($text);
    }

    public function json(array $messages, array $options = []): array
    {
        $options['_usage_operation'] = 'json';
        $options['response_format'] = ['type' => 'json_object'];
        $response = $this->chat($messages, $options);
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            throw new RuntimeException('Groq returned an empty JSON response.');
        }

        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $result = json_decode(trim($content), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
            Log::error('Groq returned invalid JSON.', [
                'error' => json_last_error_msg(),
                'content' => mb_substr($content, 0, 5000),
            ]);
            throw new RuntimeException('Groq returned invalid JSON.');
        }

        return $result;
    }

    protected function sanitizeOptions(array $options): array
    {
        unset(
            $options['model'],
            $options['thinking_level'],
            $options['thinking_budget'],
            $options['response_mime_type'],
            $options['response_schema'],
            $options['_usage_operation'],
            $options['_usage_feature'],
            $options['_usage_user_id'],
            $options['_usage_school_id'],
            $options['_usage_role'],
            $options['_usage_tutor_session_id'],
            $options['_usage_group_id'],
            $options['_usage_learning_period_id'],
            $options['_usage_subject_id'],
            $options['_usage_unit_id'],
            $options['_usage_topic_id'],
            $options['_usage_learning_objective_id'],
            $options['_usage_is_fallback'],
            $options['_usage_primary_provider']
        );
        return $options;
    }

    protected function sanitizeUsageContextValue(mixed $value): mixed
    {
        return is_scalar($value) || $value === null ? $value : null;
    }

    protected function usageContext(array $options): array
    {
        return array_filter([
            'feature' => $this->sanitizeUsageContextValue($options['_usage_feature'] ?? null),
            'user_id' => $this->sanitizeUsageContextValue($options['_usage_user_id'] ?? null),
            'school_id' => $this->sanitizeUsageContextValue($options['_usage_school_id'] ?? null),
            'role' => $this->sanitizeUsageContextValue($options['_usage_role'] ?? null),
            'tutor_session_id' => $this->sanitizeUsageContextValue($options['_usage_tutor_session_id'] ?? null),
            'group_id' => $this->sanitizeUsageContextValue($options['_usage_group_id'] ?? null),
            'learning_period_id' => $this->sanitizeUsageContextValue($options['_usage_learning_period_id'] ?? null),
            'subject_id' => $this->sanitizeUsageContextValue($options['_usage_subject_id'] ?? null),
            'unit_id' => $this->sanitizeUsageContextValue($options['_usage_unit_id'] ?? null),
            'topic_id' => $this->sanitizeUsageContextValue($options['_usage_topic_id'] ?? null),
            'learning_objective_id' => $this->sanitizeUsageContextValue($options['_usage_learning_objective_id'] ?? null),
            'is_fallback' => (bool) ($options['_usage_is_fallback'] ?? false),
            'primary_provider' => $this->sanitizeUsageContextValue($options['_usage_primary_provider'] ?? null),
        ], fn ($value) => $value !== null);
    }


    protected function latencyMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
