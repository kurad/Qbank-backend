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
            'prefix' => substr(config('services.groq.api_key'), 0, 8),
            'is_null' => config('services.groq.api_key') === null,
        ]);
        $url = 'https://api.groq.com/openai/v1/chat/completions';


        // Always generate 10 unique questions
        $fullPrompt =
            "Generate **10 different exam questions** every time this request is made.\n" .
            "Do NOT repeat questions from previous generations.\n" .
            "Vary the style, wording, and difficulty.\n\n" .
            "User request: " . $prompt;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.groq.api_key'),
            'Content-Type' => 'application/json',
        ])->post($url, [
            'model' => 'llama-3.3-70b-versatile',
            'temperature' => 1.1, // Increases randomness
            'messages' => [
                [
                    "role" => "system", 
                    "content" => 
                        "You generate exam questions.\n" .
                    "Return ONLY valid JSON, no markdown, no explanation.\n" .
                    "Format: an array of 10 question objects.\n" .
                    "Each object must have:\n" .
                    "  - question (string)\n" .
                    "  - question_type (mcq|true_false|short_answer)\n" .
                    "  - difficulty_level (remembering|understanding|applying|analyzing|evaluating|creating)\n" .
                    "  - options (array of strings for MCQ, or null for other types)\n" .
                    "  - correct_answer (string)\n"
                    ],
                [
                    "role" => "user",
                    "content" => $fullPrompt,
                ],
            ],
        ]);
        if ($response->failed()) {
            throw new \Exception('Groq API Error: ' . $response->body());
        }
        return $response->json()['choices'][0]['message']['content'];


    }
}
