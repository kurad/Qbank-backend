<?php

namespace App\Services;

use Log;
use Groq\Groq;
use Illuminate\Support\Facades\Http;

class GroqAIService
{
    public function generateQuestions($prompt)
    {
        Log::info('GROQ key debug', [
            'prefix' => substr(env('GROQ_API_KEY'), 0, 8),
            'is_null' => env('GROQ_API_KEY') === null,
        ]);
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post($url, [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                [
                    "role" => "system", 
                    "content" => 
                        "You generate exam questions.\n" .
                    "Return ONLY valid JSON, no markdown, no explanation.\n" .
                    "Format: an array of question objects.\n" .
                    "Each object must have:\n" .
                    "  - question (string)\n" .
                    "  - question_type (mcq|true_false|short_answer)\n" .
                    "  - difficulty_level (remembering|understanding|applying|analyzing|evaluating|creating)\n" .
                    "  - options (array or object, depending on type)\n" .
                    "  - correct_answer\n" .
                    "Example:\n" .
                    "[{\n" .
                    "  \"question\": \"What is 2+2?\",\n" .
                    "  \"question_type\": \"mcq\",\n" .
                    "  \"difficulty_level\": \"remembering\",\n" .
                    "  \"options\": [\"3\", \"4\", \"5\"],\n" .
                    "  \"correct_answer\": \"4\"\n" .
                    "}]"
                    ],
                [
                    "role" => "user",
                    "content" => $prompt,
                ],
            ],
        ]);
        if ($response->failed()) {
            throw new \Exception('Groq API Error: ' . $response->body());
        }
        return $response->json()['choices'][0]['message']['content'];


    }
}
