<?php

return [
    // Keep OFF until test-environment comparisons confirm learning quality.
    // Only regular student messages are adapted; formal checks stay unchanged.
    'adaptive_enabled' => (bool) env('TUTOR_ADAPTIVE_CONTEXT', false),
    // Only Tutor Opening; formal checks retain their existing evidence budgets.
    'opening_chunks' => (int) env('TUTOR_OPENING_CHUNKS', 4),
    'opening_max_tokens' => (int) env('TUTOR_OPENING_MAX_TOKENS', 2500),
];
