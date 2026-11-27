<?php

declare(strict_types=1);

namespace Classivo\Grading\Aggregate;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;

/**
 * Rank-based (relative) grading: passed candidates are ranked by marks and divided into
 * equal slices, one per label, best slice first; failed candidates get $failLabel.
 *
 * India preset: CBSE board results, labels A1..D2 over eighths of passed candidates,
 * fail = E (see SOURCES.md). Tie policy: candidates with identical marks always share
 * one grade — the grade of the best-ranked among them — so a tie never splits across a
 * boundary. CBSE's exact tie and small-cohort handling is not fully public: see
 * VERIFICATION.md; $minimumCohort lets you fall back to absolute bands for small cohorts
 * (assign() returns null then).
 */
final class PositionalGrader
{
    /** @param list<string> $labels best first */
    public function __construct(
        public readonly array $labels,
        public readonly float $passPercentage,
        public readonly string $failLabel = 'E',
        public readonly int $minimumCohort = 0,
        public readonly Rounding $rounding = new Rounding,
    ) {
        if ($labels === []) {
            throw new InvalidConfiguration('Positional grading needs at least one label.');
        }
        if (in_array($failLabel, $labels, true)) {
            throw new InvalidConfiguration('The fail label must differ from the pass labels.');
        }
    }

    /**
     * @param  array<array-key, float|int>  $percentages  candidate id => percentage
     * @return array<array-key, string>|null candidate id => label, or null when the passed cohort is below $minimumCohort
     */
    public function assign(array $percentages): ?array
    {
        $passed = [];
        $result = [];
        foreach ($percentages as $id => $pct) {
            if ($this->rounding->apply((float) $pct) >= $this->passPercentage) {
                $passed[$id] = (float) $pct;
            } else {
                $result[$id] = $this->failLabel;
            }
        }

        $n = count($passed);
        if ($n < $this->minimumCohort) {
            return null;
        }

        arsort($passed);
        $slices = count($this->labels);
        $rank = 0;
        $previous = null;
        $previousLabel = null;
        foreach ($passed as $id => $pct) {
            if ($previous !== null && $pct === $previous) {
                $result[$id] = $previousLabel;
            } else {
                $index = min($slices - 1, intdiv($rank * $slices, $n));
                $result[$id] = $this->labels[$index];
            }
            $previous = $pct;
            $previousLabel = $result[$id];
            $rank++;
        }

        $ordered = [];
        foreach (array_keys($percentages) as $id) {
            $ordered[$id] = $result[$id];
        }

        return $ordered;
    }
}
