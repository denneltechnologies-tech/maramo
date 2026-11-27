<?php

declare(strict_types=1);

namespace Classivo\Grading\Aggregate;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Rounding;

/**
 * "Best of N" aggregate: compulsory subjects are always counted, the remaining slots
 * are filled with the student's highest-scoring other subjects, and the result is the
 * average percentage of the chosen subjects.
 *
 * Commonly used as "best of five" (English + best four) for ICSE-style aggregates.
 * Ties between equal percentages keep the input order.
 */
final class BestOfAggregator
{
    /** @param list<string> $compulsory */
    public function __construct(
        public readonly int $n,
        public readonly array $compulsory = [],
        public readonly Rounding $rounding = new Rounding(Rounding::HALF_UP, 2),
    ) {
        if ($n < 1) {
            throw new InvalidConfiguration('n must be >= 1.');
        }
        if (count($compulsory) > $n) {
            throw new InvalidConfiguration('More compulsory subjects than slots.');
        }
    }

    /** @param array<string, float|int> $percentages subject => percentage */
    public function aggregate(array $percentages): BestOfResult
    {
        $chosen = [];
        foreach ($this->compulsory as $subject) {
            if (! array_key_exists($subject, $percentages)) {
                throw new InvalidConfiguration("Compulsory subject [{$subject}] has no score.");
            }
            $chosen[$subject] = (float) $percentages[$subject];
        }

        $rest = array_diff_key($percentages, $chosen);
        $order = array_flip(array_keys($rest));
        uksort($rest, static function ($a, $b) use ($rest, $order) {
            return [$rest[$b], $order[$a]] <=> [$rest[$a], $order[$b]];
        });

        foreach ($rest as $subject => $pct) {
            if (count($chosen) >= $this->n) {
                break;
            }
            $chosen[(string) $subject] = (float) $pct;
        }

        $average = $chosen === [] ? 0.0 : array_sum($chosen) / count($chosen);

        return new BestOfResult($chosen, $this->rounding->apply($average), count($chosen) === $this->n);
    }
}
