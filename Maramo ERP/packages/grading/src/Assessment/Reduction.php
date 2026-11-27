<?php

declare(strict_types=1);

namespace Classivo\Grading\Assessment;

use Classivo\Grading\InvalidConfiguration;

/**
 * How several attempts inside one component (e.g. three periodic tests) collapse into a
 * single fraction 0..1 before the component's weight is applied.
 *
 *  - AVERAGE:          mean of all attempts' ratios
 *  - BEST_N_AVERAGE:   mean of the best N ratios (e.g. "average of best two of three")
 *  - BEST_N_SUM:       sum of the best N attempts' marks over the sum of their maxima
 *  - SUM:              total marks over total maxima
 *  - BEST:             highest single ratio
 *  - LATEST:           the last attempt in the list
 */
final class Reduction
{
    public const AVERAGE = 'average';

    public const BEST_N_AVERAGE = 'best_n_average';

    public const BEST_N_SUM = 'best_n_sum';

    public const SUM = 'sum';

    public const BEST = 'best';

    public const LATEST = 'latest';

    private const MODES = [self::AVERAGE, self::BEST_N_AVERAGE, self::BEST_N_SUM, self::SUM, self::BEST, self::LATEST];

    public function __construct(
        public readonly string $mode = self::AVERAGE,
        public readonly ?int $n = null,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidConfiguration("Unknown reduction [{$mode}]. Use one of: ".implode(', ', self::MODES).'.');
        }
        if (in_array($mode, [self::BEST_N_AVERAGE, self::BEST_N_SUM], true) && ($n === null || $n < 1)) {
            throw new InvalidConfiguration("Reduction [{$mode}] needs n >= 1.");
        }
    }

    public static function bestOf(int $n): self
    {
        return new self(self::BEST_N_AVERAGE, $n);
    }

    /**
     * @param  list<Score>  $scores  already filtered for absence policy
     */
    public function reduce(array $scores): float
    {
        if ($scores === []) {
            return 0.0;
        }

        return match ($this->mode) {
            self::AVERAGE => array_sum(array_map(static fn (Score $s) => $s->ratio(), $scores)) / count($scores),
            self::BEST_N_AVERAGE => $this->bestAverage($scores),
            self::BEST_N_SUM => self::sumRatio(array_slice(self::sortBest($scores), 0, (int) $this->n)),
            self::SUM => self::sumRatio($scores),
            self::BEST => max(array_map(static fn (Score $s) => $s->ratio(), $scores)),
            self::LATEST => $scores[array_key_last($scores)]->ratio(),
        };
    }

    /** @param list<Score> $scores */
    private function bestAverage(array $scores): float
    {
        $best = array_slice(self::sortBest($scores), 0, (int) $this->n);

        return array_sum(array_map(static fn (Score $s) => $s->ratio(), $best)) / count($best);
    }

    /**
     * @param  list<Score>  $scores
     * @return list<Score>
     */
    private static function sortBest(array $scores): array
    {
        usort($scores, static fn (Score $a, Score $b) => $b->ratio() <=> $a->ratio());

        return $scores;
    }

    /** @param list<Score> $scores */
    private static function sumRatio(array $scores): float
    {
        $obtained = 0.0;
        $max = 0.0;
        foreach ($scores as $s) {
            $obtained += $s->obtained ?? 0.0;
            $max += $s->max;
        }

        return $obtained / $max;
    }
}
