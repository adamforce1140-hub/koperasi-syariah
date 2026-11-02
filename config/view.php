<?php

return [
    'aliases' => [],
    'paths' => [
        realpath(base_path('resources/views')),
    ],
    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),
];
