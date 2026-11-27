<?php

return [
    // Default driver: 'dompdf' or 'chrome'.
    'driver' => env('CLASSIVO_PDF_DRIVER', 'dompdf'),

    // Locales that should use Chrome automatically (when available), e.g. ['hi'].
    'chrome_for_locales' => array_filter(explode(',', (string) env('CLASSIVO_PDF_CHROME_LOCALES', ''))),

    'chrome' => [
        'binary' => env('CLASSIVO_PDF_CHROME_BINARY'),   // null = auto-detect
        'node_binary' => env('CLASSIVO_PDF_NODE_BINARY'),
        'npm_binary' => env('CLASSIVO_PDF_NPM_BINARY'),
        'timeout' => 60,
        'no_sandbox' => env('CLASSIVO_PDF_NO_SANDBOX', false),
    ],
];
