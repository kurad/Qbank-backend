<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'storage/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://test.revisionhub.rw',
        'https://www.test.revisionhub.rw',
        'http://localhost:8080',
    ],

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?testrevisionhub\.rw$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];