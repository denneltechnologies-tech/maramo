<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/**
 * Solver knobs.
 *
 * - seed: same seed + same problem = same timetable (the solver never touches
 *   global RNG state).
 * - timeLimitSeconds: wall-clock budget; on expiry the result is TIMEOUT with
 *   the best partial timetable found.
 * - maxSameSubjectPerDay: HARD cap on periods of one subject for one class per
 *   day (null = no hard cap; "avoid twice a day" is still a soft goal).
 * - restartBase: backtracks allowed in the first attempt; each restart doubles
 *   it and reshuffles tie-breaks, so the search stays complete in the limit.
 */
final class Options
{
    public function __construct(
        public readonly int $seed = 1,
        public readonly float $timeLimitSeconds = 10.0,
        public readonly ?int $maxSameSubjectPerDay = null,
        public readonly int $restartBase = 300,
    ) {
        if ($timeLimitSeconds <= 0) {
            throw new InvalidProblem('timeLimitSeconds must be positive.');
        }

        if ($restartBase < 1) {
            throw new InvalidProblem('restartBase must be at least 1.');
        }

        if ($maxSameSubjectPerDay !== null && $maxSameSubjectPerDay < 1) {
            throw new InvalidProblem('maxSameSubjectPerDay must be at least 1.');
        }
    }
}
