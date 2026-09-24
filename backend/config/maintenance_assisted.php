<?php

return [
    'provider' => [
        'name' => env('MAINTENANCE_ASSISTED_PROVIDER', 'configured-json'),
        'endpoint' => env('MAINTENANCE_ASSISTED_PROVIDER_ENDPOINT'),
        'key' => env('MAINTENANCE_ASSISTED_PROVIDER_KEY'),
        'model' => env('MAINTENANCE_ASSISTED_PROVIDER_MODEL'),
        'revision' => env('MAINTENANCE_ASSISTED_PROVIDER_REVISION', 'v1'),
        'timeout' => env('MAINTENANCE_ASSISTED_PROVIDER_TIMEOUT', 60),
    ],
];
