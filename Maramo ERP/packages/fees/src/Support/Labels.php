<?php

namespace Classivo\Fees\Support;

/** Translate the values of a key => English label map (option lists, badges). */
final class Labels
{
    /**
     * @param  array<string|int, string>  $labels
     * @return array<string|int, string>
     */
    public static function t(array $labels): array
    {
        return array_map(fn (string $label): string => __($label), $labels);
    }
}
