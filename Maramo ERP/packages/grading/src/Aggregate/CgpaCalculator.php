<?php

declare(strict_types=1);

namespace Classivo\Grading\Aggregate;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;

/**
 * Cumulative grade point average over a set of subject grade points, plus an
 * "indicative percentage" = CGPA x multiplier.
 *
 * India preset: multiplier 9.5 (the CBSE CCE-era conversion, see SOURCES.md). The
 * multiplier is a constructor argument because it is board- and year-specific.
 *
 * $subjectCount: if set, only the best N grade points are averaged (CBSE CCE averaged
 * the five main subjects, excluding the additional sixth subject); if the student has
 * fewer, all are used. A null grade point (a fail band without a GP) makes the CGPA
 * undefined unless $skipNullPoints is true.
 */
final class CgpaCalculator
{
    public function __construct(
        public readonly ?float $multiplier = 9.5,
        public readonly ?int $subjectCount = null,
        public readonly Rounding $cgpaRounding = new Rounding(Rounding::HALF_UP, 1),
        public readonly Rounding $percentRounding = new Rounding(Rounding::HALF_UP, 1),
        public readonly bool $skipNullPoints = false,
    ) {
        if ($subjectCount !== null && $subjectCount < 1) {
            throw new InvalidConfiguration('subjectCount must be >= 1.');
        }
        if ($multiplier !== null && $multiplier <= 0) {
            throw new InvalidConfiguration('multiplier must be positive.');
        }
    }

    /** @param array<array-key, float|int|null> $gradePoints */
    public function cgpa(array $gradePoints): ?float
    {
        $points = [];
        foreach ($gradePoints as $gp) {
            if ($gp === null) {
                if ($this->skipNullPoints) {
                    continue;
                }

                return null;
            }
            $points[] = (float) $gp;
        }
        if ($points === []) {
            return null;
        }
        rsort($points);
        if ($this->subjectCount !== null) {
            $points = array_slice($points, 0, $this->subjectCount);
        }

        return $this->cgpaRounding->apply(array_sum($points) / count($points));
    }

    /** @param array<array-key, float|int|null> $gradePoints */
    public function indicativePercentage(array $gradePoints): ?float
    {
        $cgpa = $this->cgpa($gradePoints);
        if ($cgpa === null || $this->multiplier === null) {
            return null;
        }

        return $this->percentRounding->apply(min(100.0, $cgpa * $this->multiplier));
    }

    /** Subject-wise indicative percentage = grade point x multiplier. */
    public function subjectPercentage(float $gradePoint): ?float
    {
        if ($this->multiplier === null) {
            return null;
        }

        return $this->percentRounding->apply(min(100.0, $gradePoint * $this->multiplier));
    }
}
