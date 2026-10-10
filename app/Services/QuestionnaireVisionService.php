<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class QuestionnaireVisionService
{
    public function extractImageAsJson(
        string $absolutePath,
        string $mimeType,
        string $prompt,
        array $schema
    ): array {
        $apiKey = config('services.gemini.key');

        if (!$apiKey) {
            throw new RuntimeException(
                'Gemini API key is required for questionnaire image extraction.'
            );
        }

        $model = config('services.gemini.model', 'gemini-3.6-flash');
        $baseUrl = rtrim(
            config(
                'services.gemini.url',
                'https://generativelanguage.googleapis.com/v1beta'
            ),
            '/'
        );

        $bytes = file_get_contents($absolutePath);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read uploaded image.');
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $prompt,
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => base64_encode($bytes),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
                'maxOutputTokens' => 12000,
            ],
        ];

        $response = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(120)
            ->post(
                "{$baseUrl}/models/{$model}:generateContent",
                $payload
            );

        if ($response->failed()) {
            $message =
                data_get($response->json(), 'error.message')
                ?: $response->body();

            throw new RuntimeException(
                'Gemini image extraction failed: ' . $message
            );
        }

        $text = data_get(
            $response->json(),
            'candidates.0.content.parts.0.text'
        );

        if (!$text) {
            throw new RuntimeException(
                'Gemini returned an empty image extraction result.'
            );
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Gemini returned invalid JSON for the uploaded image.'
            );
        }

        return $decoded;
    }
}
