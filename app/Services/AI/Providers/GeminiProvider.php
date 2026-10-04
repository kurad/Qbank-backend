<?php
namespace App\Services\AI\Providers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
class GeminiProvider
{
    public function chat(array $messages, array $options = []): array
    {
        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            throw new RuntimeException('Gemini API key is not configured.');
        }
        $model = $options['model'] ?? config('services.gemini.model', 'gemini-3.6-flash');
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
            $geminiRole = $role === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $content]],
            ];
        }
        if (empty($contents)) {
            throw new RuntimeException('Gemini request contains no messages.');
        }
        $payload = ['contents' => $contents];
        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }
        $generationConfig = [];
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = $options['max_tokens'];
        }
        if (!empty($options['thinking_level'])) {
            $generationConfig['thinkingConfig'] = [
                'thinkingLevel' => $options['thinking_level'],
            ];
        } elseif (array_key_exists('thinking_budget', $options)) {
            $generationConfig['thinkingConfig'] = [
                'thinkingBudget' => (int) $options['thinking_budget'],
            ];
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
        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post("{$url}/models/{$model}:generateContent", $payload);
            if ($response->failed()) {
                $body = $response->body();
                $json = $response->json();
                Log::error('Gemini provider request failed.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $body,
                    'json' => $json,
                ]);
                $message = 'Unknown Gemini provider error.';
                if (is_array($json) && isset($json['error'])) {
                    if (is_array($json['error'])) {
                        $message = $json['error']['message'] ?? $json['error']['status'] ?? json_encode($json['error']);
                    } else {
                        $message = (string) $json['error'];
                    }
                }
                throw new RuntimeException('Gemini provider error: ' . $message);
            }
            $data = $response->json();
            if (!is_array($data)) {
                throw new RuntimeException('Gemini returned an invalid response.');
            }
            $finishReason = $data['candidates'][0]['finishReason'] ?? null;
            Log::info('Gemini response received.', [
                'model' => $model,
                'finish_reason' => $finishReason,
                'usage' => $data['usageMetadata'] ?? null,
            ]);
            if ($finishReason === 'MAX_TOKENS') {
                Log::warning('Gemini response reached the output token limit.', [
                    'model' => $model,
                    'max_output_tokens' => $generationConfig['maxOutputTokens'] ?? null,
                    'thinking_config' => $generationConfig['thinkingConfig'] ?? null,
                    'usage' => $data['usageMetadata'] ?? null,
                ]);
            }
            return $data;
        } catch (\Throwable $e) {
            Log::error('Gemini service request failed.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    public function text(array $messages, array $options = []): string
    {
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
    public function json(array $messages, array $schema, array $options = []): array
    {
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
        $text = trim($text);
        $result = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
            Log::error('Gemini returned invalid JSON.', [
                'error' => json_last_error_msg(),
                'content' => mb_substr($text, 0, 5000),
            ]);
            throw new RuntimeException('Gemini returned invalid JSON.');
        }
        return $result;
    }
}
