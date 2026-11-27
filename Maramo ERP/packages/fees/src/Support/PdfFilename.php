<?php

namespace Classivo\Fees\Support;

use Illuminate\Support\Str;

/** A filesystem- and header-safe fragment for a PDF download name. */
final class PdfFilename
{
    public static function safe(?string $value): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $value), '-');

        return $safe !== '' ? $safe : Str::random(6);
    }
}
