<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AIUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GeminiProvider
{
    public function __construct(protected AIUsageRecorder $usageRecorder)
    {
    }

    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? config('services.gemini.model');
        $operation = $options['_usage_operation'] ?? 'chat';
        $context = $this->usageContext($options);
        $startedAt = microtime(true);
        $httpStatus = null;

        try {
            $apiKey = config('services.gemini.key');
            if (!$apiKey) {
                throw new RuntimeException('Gemini API key is not configured.');
            }

            $url = rtrim(config('services.gemini.url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
            $contents = [];
            $systemInstruction = null;

            foreach ($messages as $message) {
                $role = $message['role'] ?? 'user';
                $content = $message['content'] ?? '';

                if ($role === 'system') {
                    $systemInstruction = $content;
                    continue;
                }

                $contents[] = [
                    'role' => $role === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $content]],
                ];
            }

            if (empty($contents)) {
                throw new RuntimeException('Gemini request contains no messages.');
            }

            $payload = ['contents' => $contents];
            if ($systemInstruction) {
                $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
            }

            $generationConfig = [];
            if (isset($options['temperature'])) {
                $generationConfig['temperature'] = $options['temperature'];
            }
            if (isset($options['max_tokens'])) {
                $generationConfig['maxOutputTokens'] = $options['max_tokens'];
            }
            if (!empty($options['thinking_level'])) {
                $generationConfig['thinkingConfig'] = ['thinkingLevel' => $options['thinking_level']];
            } elseif (array_key_exists('thinking_budget', $options)) {
                $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $options['thinking_budget']];
            }
            if (!empty($options['response_mime_type'])) {
                $generationConfig['responseMimeType'] = $options['response_mime_type'];
            }
            if (isset($options['response_schema']) && is_array($options['response_schema'])) {
                $generationConfig['responseSchema'] = $options['response_schema'];
            }
            if (!empty($generationConfig)) {
                $payload['generationConfig'] = $generationConfig;
            }

            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$url}/models/{$model}:generateContent", $payload);

            $httpStatus = $response->status();

            if ($response->failed()) {
                $json = $response->json();
                Log::error('Gemini provider request failed.', [
                    'model' => $model,
                    'status' => $httpStatus,
                    'body' => $response->body(),
                    'json' => $json,
                ]);

                $message = 'Unknown Gemini provider error.';
                if (is_array($json) && isset($json['error'])) {
                    $message = is_array($json['error'])
                        ? ($json['error']['message'] ?? $json['error']['status'] ?? json_encode($json['error']))
                        : (string) $json['error'];
                }

                throw new RuntimeException('Gemini provider error: ' . $message);
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new RuntimeException('Gemini returned an invalid response.');
            }

            $finishReason = $data['candidates'][0]['finishReason'] ?? null;
            $usage = $data['usageMetadata'] ?? [];

            Log::info('Gemini response received.', [
                'model' => $model,
                'finish_reason' => $finishReason,
                'usage' => $usage ?: null,
            ]);

            $this->usageRecorder->success(
                'gemini',
                $model,
                $operation,
                is_array($usage) ? $usage : [],
                $this->latencyMs($startedAt),
                $finishReason,
                $context + ['http_status' => $httpStatus]
            );

            if ($finishReason === 'MAX_TOKENS') {
                Log::warning('Gemini response reached the output token limit.', [
                    'model' => $model,
                    'max_output_tokens' => $generationConfig['maxOutputTokens'] ?? null,
                    'thinking_config' => $generationConfig['thinkingConfig'] ?? null,
                    'usage' => $usage ?: null,
                ]);
            }

            return $data;
        } catch (Throwable $e) {
            $this->usageRecorder->failure(
                'gemini',
                $model,
                $operation,
                $e,
                $this->latencyMs($startedAt),
                $httpStatus,
                $context
            );

            Log::error('Gemini service request failed.', [
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
        $finishReason = $response['candidates'][0]['finishReason'] ?? null;
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new RuntimeException('Gemini returned an empty response.');
        }
        if ($finishReason === 'MAX_TOKENS') {
            throw new RuntimeException('Gemini response was truncated because the output token limit was reached.');
        }

        return trim($text);
    }

    public function multimodalText(array $messages, array $attachments = [], array $options = []): string
    {
        $model = $options['model'] ?? config('services.gemini.model');
        $context = $this->usageContext($options);
        $startedAt = microtime(true);
        $httpStatus = null;

        try {
            $apiKey = config('services.gemini.key');
            if (!$apiKey) {
                throw new RuntimeException('Gemini API key is not configured.');
            }

            $url = rtrim(config('services.gemini.url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
            $contents = [];
            $systemInstruction = null;

            foreach ($messages as $index => $message) {
                $role = $message['role'] ?? 'user';
                $content = (string) ($message['content'] ?? '');

                if ($role === 'system') {
                    $systemInstruction = $content;
                    continue;
                }

                $parts = [['text' => $content]];
                if ($index === array_key_last($messages) && $role === 'user') {
                    foreach ($attachments as $attachment) {
                        $data = $attachment['data'] ?? null;
                        $mime = $attachment['mime_type'] ?? null;
                        if ($data && $mime) {
                            $parts[] = [
                                'inlineData' => [
                                    'mimeType' => $mime,
                                    'data' => $data,
                                ],
                            ];
                        }
                    }
                }

                $contents[] = [
                    'role' => $role === 'assistant' ? 'model' : 'user',
                    'parts' => $parts,
                ];
            }

            if (empty($contents)) {
                throw new RuntimeException('Gemini request contains no messages.');
            }

            $payload = ['contents' => $contents];
            if ($systemInstruction) {
                $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
            }

            $generationConfig = [];
            if (isset($options['temperature'])) {
                $generationConfig['temperature'] = $options['temperature'];
            }
            if (isset($options['max_tokens'])) {
                $generationConfig['maxOutputTokens'] = $options['max_tokens'];
            }
            if (!empty($generationConfig)) {
                $payload['generationConfig'] = $generationConfig;
            }

            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$url}/models/{$model}:generateContent", $payload);

            $httpStatus = $response->status();
            if ($response->failed()) {
                $json = $response->json();
                $message = is_array($json)
                    ? ($json['error']['message'] ?? 'Unknown Gemini provider error.')
                    : 'Unknown Gemini provider error.';
                throw new RuntimeException('Gemini provider error: ' . $message);
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new RuntimeException('Gemini returned an invalid multimodal response.');
            }

            $finishReason = $data['candidates'][0]['finishReason'] ?? null;
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            $usage = $data['usageMetadata'] ?? [];

            $this->usageRecorder->success(
                'gemini',
                $model,
                'multimodal',
                is_array($usage) ? $usage : [],
                $this->latencyMs($startedAt),
                $finishReason,
                $context + ['http_status' => $httpStatus]
            );

            if (!$text) {
                throw new RuntimeException('Gemini returned an empty multimodal response.');
            }
            if ($finishReason === 'MAX_TOKENS') {
                throw new RuntimeException('Gemini response was truncated because the output token limit was reached.');
            }

            return trim($text);
        } catch (Throwable $e) {
            // If a successful HTTP response was already recorded, avoid a second failed provider record
            // for downstream content validation errors.
            if (!$httpStatus || $httpStatus >= 400) {
                $this->usageRecorder->failure(
                    'gemini',
                    $model,
                    'multimodal',
                    $e,
                    $this->latencyMs($startedAt),
                    $httpStatus,
                    $context
                );
            }
            throw $e;
        }
    }

    public function json(array $messages, array $schema, array $options = []): array
    {
        $options['_usage_operation'] = 'json';
        $options['response_mime_type'] = 'application/json';
        $options['response_schema'] = $schema;
        $response = $this->chat($messages, $options);
        $finishReason = $response['candidates'][0]['finishReason'] ?? null;
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$text) {
            throw new RuntimeException('Gemini returned an empty JSON response.');
        }
        if ($finishReason === 'MAX_TOKENS') {
            Log::warning('Gemini structured JSON response was truncated.', [
                'content' => mb_substr((string) $text, 0, 5000),
                'usage' => $response['usageMetadata'] ?? null,
            ]);
            throw new RuntimeException('Gemini JSON response was truncated because the output token limit was reached.');
        }

        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $result = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
            Log::error('Gemini returned invalid JSON.', [
                'error' => json_last_error_msg(),
                'content' => mb_substr($text, 0, 5000),
            ]);
            throw new RuntimeException('Gemini returned invalid JSON.');
        }

        return $result;
    }

    protected function usageContext(array $options): array
    {
        return [
            'feature' => $options['_usage_feature'] ?? null,
            'is_fallback' => (bool) ($options['_usage_is_fallback'] ?? false),
            'primary_provider' => $options['_usage_primary_provider'] ?? null,
        ];
    }

    protected function latencyMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
