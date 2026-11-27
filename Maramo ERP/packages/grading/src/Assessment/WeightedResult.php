<?php

declare(strict_types=1);

namespace Classivo\Grading\Assessment;

use Classivo\Grading\Percent;

final class WeightedResult
{
    /** @param array<string, float> $breakdown component key => weighted marks */
    public function __construct(
        public readonly float $total,
        public readonly float $max,
        public readonly array $breakdown,
    ) {}

    public function percentage(): float
    {
        return Percent::of($this->total, $this->max);
    }

    public function toScore(): Score
    {
        return new Score(min($this->total, $this->max), $this->max);
    }
}
