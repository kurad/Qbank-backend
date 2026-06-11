<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'storage/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://revisionhub.rw',
        'https://www.revisionhub.rw',
    ],

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?revisionhub\.rw$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];