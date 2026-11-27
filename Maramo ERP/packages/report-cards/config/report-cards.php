<?php

use Classivo\Core\Models\School;

return [
    /*
    | Load the optional persistence layer (template + issued-certificate models and
    | migrations). Requires classivo/core. The renderer itself never needs it.
    */
    'persistence' => env('REPORT_CARDS_PERSISTENCE', class_exists(School::class)),

    /* Disk + directory used when storing issued certificate PDFs. */
    'disk' => env('REPORT_CARDS_DISK', 'local'),
    'directory' => 'certificates',

    /* Prefixes for the running number series. */
    'certificate_prefix' => 'CERT',
    'transfer_certificate_prefix' => 'TC',

    /* Default paper for the sample templates. */
    'paper' => 'a4',
];
