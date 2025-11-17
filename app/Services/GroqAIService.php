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
                ["role" => "system", "content" => "You generate high-quality exam questions."],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        if ($response->failed()) {
            throw new \Exception('Groq API Error: ' . $response->body());
        }
        return $response->json()['choices'][0]['message']['content'];
    }
}
