<?php

return [
    // Keep OFF until test-environment comparisons confirm learning quality.
    // Only regular student messages are adapted; formal checks stay unchanged.
    'adaptive_enabled' => (bool) env('TUTOR_ADAPTIVE_CONTEXT', false),
];
