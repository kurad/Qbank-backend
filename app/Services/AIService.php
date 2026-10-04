<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AIService
{
    /**
     * Send a chat completion request to the configured AI provider.
     */
    public function chat(array $messages, array $options = []): array
    {
        $provider = config('services.ai.provider', 'openrouter');

        return match ($provider) {
            'openrouter' => $this->openRouterChat($messages, $options),

            default => throw new RuntimeException(
                "Unsupported AI provider: {$provider}"
            ),
        };
    }

    /**
     * Request and decode a JSON response from the configured AI provider.
     */
    public function json(array $messages, array $options = []): array
    {
        $options['response_format'] = [
            'type' => 'json_object',
        ];

        $response = $this->chat($messages, $options);

        $content = $response['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            Log::warning('AI returned an empty response.', [
                'provider' => config('services.ai.provider'),
                'response' => $response,
            ]);

            throw new RuntimeException(
                'The AI returned an empty response.'
            );
        }

        $content = trim($content);

        /*
        |--------------------------------------------------------------------------
        | Remove markdown code fences
        |--------------------------------------------------------------------------
        */

        $content = preg_replace(
            '/^```(?:json)?\s*/i',
            '',
            $content
        );

        $content = preg_replace(
            '/\s*```$/',
            '',
            $content
        );

        $content = trim($content);

        /*
        |--------------------------------------------------------------------------
        | Decode JSON
        |--------------------------------------------------------------------------
        */

        $result = json_decode($content, true);

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($result)
        ) {
            Log::warning('AI returned invalid JSON.', [
                'provider' => config('services.ai.provider'),
                'error' => json_last_error_msg(),
                'content' => mb_substr($content, 0, 5000),
            ]);

            throw new RuntimeException(
                'The AI returned an invalid JSON response.'
            );
        }

        return $result;
    }

    /**
     * OpenRouter implementation.
     */
    protected function openRouterChat(
        array $messages,
        array $options = []
    ): array {
        $apiKey = config('services.openrouter.key');

        $url = config(
            'services.openrouter.url',
            'https://openrouter.ai/api/v1/chat/completions'
        );

        if (!$apiKey) {
            throw new RuntimeException(
                'OpenRouter API key is not configured.'
            );
        }

        $primaryModel = config(
            'services.ai.model',
            'google/gemma-4-31b-it:free'
        );

        $fallbackModels = config(
            'services.ai.fallback_models',
            []
        );

        $models = array_values(array_unique([
            $primaryModel,
            ...$fallbackModels,
        ]));

        $payload = array_merge([
            'model' => $primaryModel,
            'models' => $models,

            'messages' => $messages,

            'temperature' => 0.2,

            'max_tokens' => 4096,
        ], $options);

        try {

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->withHeaders([
                    'HTTP-Referer' => config(
                        'app.url',
                        'http://localhost'
                    ),

                    'X-Title' => config(
                        'app.name',
                        'Education Platform'
                    ),
                ])
                ->timeout(120)
                ->post($url, $payload);

            if ($response->failed()) {

                $body = $response->body();
                $json = $response->json();

                Log::error('AI provider request failed.', [
                    'provider' => config('services.ai.provider'),
                    'model' => config('services.ai.model'),
                    'status' => $response->status(),
                    'body' => $body,
                    'json' => $json,
                ]);

                $errorMessage = 'Unknown provider error';

                if (is_array($json) && isset($json['error'])) {

                    if (is_array($json['error'])) {

                        $errorMessage =
                            $json['error']['message']
                            ?? $json['error']['code']
                            ?? 'Unknown provider error';

                        /*
             * OpenRouter may include additional information
             * about the upstream provider inside metadata.
             */
                        if (
                            isset($json['error']['metadata']) &&
                            is_array($json['error']['metadata'])
                        ) {
                            $metadata = $json['error']['metadata'];

                            if (!empty($metadata['raw'])) {
                                $errorMessage .=
                                    ' | Provider details: ' .
                                    (string) $metadata['raw'];
                            }

                            if (!empty($metadata['provider_name'])) {
                                $errorMessage .=
                                    ' | Provider: ' .
                                    (string) $metadata['provider_name'];
                            }
                        }
                    } else {

                        $errorMessage = (string) $json['error'];
                    }
                }

                throw new RuntimeException(
                    'AI provider error: ' . $errorMessage
                );
            }

            $data = $response->json();

            Log::info('AI provider response received.', [
                'provider' => config('services.ai.provider'),
                'model' => $data['model'] ?? null,
                'finish_reason' =>
                $data['choices'][0]['finish_reason'] ?? null,
                'usage' => $data['usage'] ?? null,
            ]);

            if (!is_array($data)) {
                throw new RuntimeException(
                    'The AI provider returned an invalid response.'
                );
            }

            return $data;
        } catch (\Throwable $e) {

            Log::error('AI service request failed.', [
                'provider' => config('services.ai.provider'),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
