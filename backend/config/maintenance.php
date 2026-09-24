<?php

return [
    'official_knowledge' => [
        'python_executable' => env('MAINTENANCE_OFFICIAL_PYTHON_EXECUTABLE'),
        'python_major' => 3,
        'python_minor' => 11,
        'pypdf_version' => '6.14.2',
        'cryptography_version' => '50.0.1',
        'preflight_timeout_seconds' => 10,
        'extraction_timeout_seconds' => 120,
    ],
];
