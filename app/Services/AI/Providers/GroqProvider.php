<?php
namespace App\Services\AI\Providers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
class GroqProvider
{
    protected string $url = 'https://api.groq.com/openai/v1/chat/completions';
    public function chat(array $messages, array $options = []): array
    {
        $apiKey = config('services.groq.key');
        if (!$apiKey) {
            throw new RuntimeException('Groq API key is not configured.');
        }
        $model = $options['model'] ?? config('services.groq.model', 'openai/gpt-oss-120b');
        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 4096,
        ], $this->sanitizeOptions($options));
        $payload['model'] = $model;
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(120)
                ->post($this->url, $payload);
            if ($response->failed()) {
                $body = $response->body();
                $json = $response->json();
                Log::error('Groq provider request failed.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $body,
                    'json' => $json,
                ]);
                $errorMessage = 'Unknown Groq provider error.';
                if (is_array($json) && isset($json['error'])) {
                    if (is_array($json['error'])) {
                        $errorMessage = $json['error']['message']
                            ?? $json['error']['code']
                            ?? json_encode($json['error']);
                    } else {
                        $errorMessage = (string) $json['error'];
                    }
                }
                throw new RuntimeException('Groq provider error: ' . $errorMessage);
            }
            $data = $response->json();
            if (!is_array($data)) {
                throw new RuntimeException('Groq returned an invalid response.');
            }
            Log::info('Groq response received.', [
                'model' => $data['model'] ?? $model,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                'usage' => $data['usage'] ?? null,
            ]);
            return $data;
        } catch (\Throwable $e) {
            Log::error('Groq service request failed.', [
                'model' => $model,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    public function text(array $messages, array $options = []): string
    {
        $response = $this->chat($messages, $options);
        $text = $response['choices'][0]['message']['content'] ?? null;
        if (!$text) {
            throw new RuntimeException('Groq returned an empty response.');
        }
        return trim($text);
    }
    public function json(array $messages, array $options = []): array
    {
        $options['response_format'] = ['type' => 'json_object'];
        $response = $this->chat($messages, $options);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!$content) {
            throw new RuntimeException('Groq returned an empty JSON response.');
        }
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);
        $result = json_decode($content, true);
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
            $options['response_schema']
        );
        return $options;
    }
}
