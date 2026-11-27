<?php

namespace Classivo\Hr\Support;

use Illuminate\Support\Facades\Lang;

/** Translates the English value maps held in model constants (statuses, types, kinds). */
class HrLabels
{
    public static function monthYear(int $month, int $year): string
    {
        return __('hr::enums.months.'.$month).' '.$year;
    }

    /**
     * @param  array<string, string>  $map  key => English label (the model constant)
     * @param  string  $group  e.g. "staff.statuses" (lang/*\/enums.php path)
     * @return array<string, string>
     */
    public static function enum(array $map, string $group): array
    {
        $out = [];

        foreach ($map as $key => $english) {
            $path = 'hr::enums.'.$group.'.'.$key;
            $out[$key] = Lang::has($path) ? (string) __($path) : (string) $english;
        }

        return $out;
    }
}
