<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AIUsageRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAIProvider
{
    protected const SUPPORTED_IMAGE_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/webp',
        'image/gif',
    ];

    public function __construct(protected AIUsageRecorder $usageRecorder)
    {
    }

    public function chat(array $messages, array $options = []): array
    {
        $options['_usage_operation'] = $options['_usage_operation'] ?? 'chat';
        return $this->request($messages, $options);
    }

    public function text(array $messages, array $options = []): string
    {
        $options['_usage_operation'] = $options['_usage_operation'] ?? 'text';
        $response = $this->request($messages, $options);
        $text = $this->extractText($response);

        if ($text === '') {
            throw new RuntimeException('OpenAI returned an empty response.');
        }

        return $text;
    }

    public function multimodalText(
        array $messages,
        array $attachments = [],
        array $options = []
    ): string {
        if (!$this->supportsAttachments($attachments)) {
            throw new RuntimeException(
                'OpenAI multimodal path currently supports image attachments only.'
            );
        }

        $options['_usage_operation'] = 'multimodal';
        $response = $this->request($messages, $options, $attachments);
        $text = $this->extractText($response);

        if ($text === '') {
            throw new RuntimeException('OpenAI returned an empty multimodal response.');
        }

        return $text;
    }

    public function supportsAttachments(array $attachments): bool
    {
        if (empty($attachments)) {
            return false;
        }

        foreach ($attachments as $attachment) {
            $mime = strtolower(trim((string) ($attachment['mime_type'] ?? '')));
            $data = $attachment['data'] ?? null;

            if (!$data || !in_array($mime, self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    public function json(array $messages, array $schema = [], array $options = []): array
    {
        $options['_usage_operation'] = $options['_usage_operation'] ?? 'json';

        if (!empty($schema)) {
            $options['_response_schema'] = $schema;
        } else {
            $options['_json_object'] = true;
        }

        $response = $this->request($messages, $options);
        $text = $this->extractText($response);

        if ($text === '') {
            throw new RuntimeException('OpenAI returned an empty JSON response.');
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Log::error('OpenAI returned invalid JSON.', [
                'error' => json_last_error_msg(),
                'content' => mb_substr($text, 0, 5000),
            ]);
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return $decoded;
    }

    protected function request(
        array $messages,
        array $options = [],
        array $attachments = []
    ): array {
        $model = $options['model'] ?? config('services.openai.model', 'gpt-6-luna');
        $operation = $options['_usage_operation'] ?? 'chat';
        $context = $this->usageContext($options);
        $startedAt = microtime(true);
        $httpStatus = null;

        try {
            $apiKey = config('services.openai.key');
            if (!$apiKey) {
                throw new RuntimeException('OpenAI API key is not configured.');
            }

            $baseUrl = rtrim(config('services.openai.url', 'https://api.openai.com/v1'), '/');

            $payload = [
                'model' => $model,
                'input' => empty($attachments)
                    ? $this->normalizeMessages($messages)
                    : $this->normalizeMultimodalMessages($messages, $attachments),
                'store' => false,
            ];

            if (isset($options['max_tokens'])) {
                $payload['max_output_tokens'] = (int) $options['max_tokens'];
            }

            // GPT-6 reasoning models reject sampling parameters such as
            // temperature/top_p whenever reasoning effort is not `none`.
            // RevisionHub callers historically send Gemini-style `thinking_level`,
            // so translate it to the Responses API reasoning.effort field.
            $reasoningEffort = $this->normalizeReasoningEffort(
                $options['reasoning_effort'] ?? $options['thinking_level'] ?? null
            );

            if ($reasoningEffort !== null) {
                $payload['reasoning'] = ['effort' => $reasoningEffort];
            }

            // GPT-6 Luna defaults to reasoning when no effort is supplied, so
            // temperature is safe only when the request explicitly disables
            // reasoning with effort=none.
            if ($reasoningEffort === 'none' && isset($options['temperature'])) {
                $payload['temperature'] = (float) $options['temperature'];
            }

            if (!empty($options['_response_schema']) && is_array($options['_response_schema'])) {
                $payload['text'] = [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'revisionhub_response',
                        'schema' => $this->normalizeJsonSchema($options['_response_schema']),
                        'strict' => false,
                    ],
                ];
            } elseif (!empty($options['_json_object'])) {
                $payload['text'] = [
                    'format' => [
                        'type' => 'json_object',
                    ],
                ];
            }

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(120)
                ->post("{$baseUrl}/responses", $payload);

            $httpStatus = $response->status();

            if ($response->failed()) {
                $json = $response->json();
                $message = is_array($json)
                    ? data_get($json, 'error.message', 'Unknown OpenAI provider error.')
                    : 'Unknown OpenAI provider error.';

                Log::error('OpenAI provider request failed.', [
                    'model' => $model,
                    'status' => $httpStatus,
                    'body' => $response->body(),
                    'json' => $json,
                    'operation' => $operation,
                ]);

                throw new RuntimeException('OpenAI provider error: ' . $message);
            }

            $data = $response->json();
            if (!is_array($data)) {
                throw new RuntimeException('OpenAI returned an invalid response.');
            }

            $actualModel = $data['model'] ?? $model;
            $status = $data['status'] ?? null;
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

            if (($data['error'] ?? null) !== null) {
                $message = data_get($data, 'error.message', 'OpenAI returned an error response.');
                throw new RuntimeException((string) $message);
            }

            if ($status === 'incomplete') {
                $reason = data_get($data, 'incomplete_details.reason', 'unknown');
                throw new RuntimeException("OpenAI response was incomplete: {$reason}.");
            }

            Log::info('OpenAI response received.', [
                'model' => $actualModel,
                'status' => $status,
                'operation' => $operation,
                'usage' => $usage ?: null,
            ]);

            $this->usageRecorder->success(
                'openai',
                $actualModel,
                $operation,
                $usage,
                $this->latencyMs($startedAt),
                $status,
                $context + ['http_status' => $httpStatus]
            );

            return $data;
        } catch (Throwable $e) {
            $this->usageRecorder->failure(
                'openai',
                $model,
                $operation,
                $e,
                $this->latencyMs($startedAt),
                $httpStatus,
                $context
            );

            Log::error('OpenAI service request failed.', [
                'model' => $model,
                'operation' => $operation,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }


    protected function normalizeReasoningEffort(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = strtolower(trim((string) $value));

        // Accept both RevisionHub/Gemini vocabulary and OpenAI vocabulary.
        $aliases = [
            'minimal' => 'low',
            'extra_high' => 'xhigh',
            'extra-high' => 'xhigh',
        ];

        $value = $aliases[$value] ?? $value;

        return in_array($value, ['none', 'low', 'medium', 'high', 'xhigh', 'max'], true)
            ? $value
            : null;
    }

    protected function normalizeMessages(array $messages): array
    {
        return collect($messages)
            ->map(function (array $message) {
                $role = $message['role'] ?? 'user';
                $content = $message['content'] ?? '';

                if (!in_array($role, ['system', 'developer', 'user', 'assistant'], true)) {
                    $role = 'user';
                }

                return [
                    'role' => $role,
                    'content' => is_string($content) ? $content : json_encode($content),
                ];
            })
            ->values()
            ->all();
    }

    protected function normalizeMultimodalMessages(array $messages, array $attachments): array
    {
        $lastUserIndex = null;
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? 'user') === 'user') {
                $lastUserIndex = $index;
            }
        }

        return collect($messages)
            ->map(function (array $message, int $index) use ($attachments, $lastUserIndex) {
                $role = $message['role'] ?? 'user';
                $content = $message['content'] ?? '';

                if (!in_array($role, ['system', 'developer', 'user', 'assistant'], true)) {
                    $role = 'user';
                }

                if ($role !== 'user' || $index !== $lastUserIndex) {
                    return [
                        'role' => $role,
                        'content' => is_string($content) ? $content : json_encode($content),
                    ];
                }

                $parts = [];
                $text = is_string($content) ? $content : json_encode($content);
                if ($text !== '') {
                    $parts[] = [
                        'type' => 'input_text',
                        'text' => $text,
                    ];
                }

                foreach ($attachments as $attachment) {
                    $mime = strtolower(trim((string) ($attachment['mime_type'] ?? '')));
                    $data = (string) ($attachment['data'] ?? '');

                    if ($data === '' || !in_array($mime, self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
                        continue;
                    }

                    $parts[] = [
                        'type' => 'input_image',
                        'image_url' => "data:{$mime};base64,{$data}",
                        'detail' => 'auto',
                    ];
                }

                return [
                    'role' => 'user',
                    'content' => $parts,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Convert provider-specific / legacy schema type names to standard JSON Schema
     * before sending them to OpenAI Structured Outputs.
     *
     * Gemini accepts uppercase type names such as OBJECT/STRING/ARRAY, while
     * OpenAI expects standard lowercase JSON Schema names.
     */
    protected function normalizeJsonSchema(array $schema): array
    {
        $typeMap = [
            'OBJECT' => 'object',
            'STRING' => 'string',
            'ARRAY' => 'array',
            'INTEGER' => 'integer',
            'NUMBER' => 'number',
            'BOOLEAN' => 'boolean',
            'NULL' => 'null',
        ];

        $walk = function (mixed $value) use (&$walk, $typeMap): mixed {
            if (!is_array($value)) {
                return $value;
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                if ($key === 'type' && is_string($item)) {
                    $normalized[$key] = $typeMap[strtoupper($item)] ?? strtolower($item);
                    continue;
                }

                if ($key === 'type' && is_array($item)) {
                    $normalized[$key] = array_map(
                        fn ($type) => is_string($type)
                            ? ($typeMap[strtoupper($type)] ?? strtolower($type))
                            : $type,
                        $item
                    );
                    continue;
                }

                $normalized[$key] = $walk($item);
            }

            return $normalized;
        };

        return $walk($schema);
    }

    protected function extractText(array $response): string
    {
        foreach (($response['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return trim((string) $content['text']);
                }
            }
        }

        return '';
    }

    protected function sanitizeUsageContextValue(mixed $value): mixed
    {
        return is_scalar($value) || $value === null ? $value : null;
    }

    protected function usageContext(array $options): array
    {
        return array_filter([
            'feature' => $this->sanitizeUsageContextValue($options['_usage_feature'] ?? null),
            'is_fallback' => (bool) ($options['_usage_is_fallback'] ?? false),
            'primary_provider' => $this->sanitizeUsageContextValue($options['_usage_primary_provider'] ?? null),
        ], fn ($value) => $value !== null);
    }

    protected function latencyMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
