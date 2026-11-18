<?php

/**
 * Optional administrator-maintained USD per million token pricing.
 * Set AI_MODEL_PRICING_JSON in .env as a JSON object keyed by provider/model.
 * Example: {"gemini/gemini-MODEL":{"input":0.0,"output":0.0,"cached_input":0.0}}
 * Replace sample zeroes with real contract prices; unspecified models show no estimate.
 * Pricing is NOT inferred from model names and never changes routing.
 */
return [
    'models' => json_decode((string) env('AI_MODEL_PRICING_JSON', '{}'), true) ?: [],
    'billing_cooldown_seconds' => env('AI_BILLING_COOLDOWN_SECONDS', 300),
    'rate_cooldown_seconds' => env('AI_RATE_COOLDOWN_SECONDS', 45),
];
