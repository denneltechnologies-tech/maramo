<?php

declare(strict_types=1);

namespace Classivo\Grading;

/** Small numeric helpers shared by the engine. */
final class Percent
{
    public static function of(float $obtained, float $max): float
    {
        if ($max <= 0) {
            throw new InvalidConfiguration('Maximum marks must be greater than zero.');
        }

        return $obtained / $max * 100;
    }

    /** Marks needed to reach $percent of $max, rounded up to the nearest $step. */
    public static function marksFor(float $percent, float $max, float $step = 1.0): float
    {
        $raw = round($percent * $max / 100, 9);
        if ($step <= 0) {
            return $raw;
        }

        return ceil(round($raw / $step, 9)) * $step;
    }
}
