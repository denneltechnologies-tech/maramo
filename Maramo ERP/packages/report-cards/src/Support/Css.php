<?php

namespace Classivo\ReportCards\Support;

final class Css
{
    /** Accept only #rgb / #rrggbb colours so user input can never inject CSS. */
    public static function color(string $value, string $fallback = '#1e293b'): string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : $fallback;
    }

    public static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
