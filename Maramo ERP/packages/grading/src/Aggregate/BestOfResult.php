<?php

declare(strict_types=1);

namespace Classivo\Grading\Aggregate;

final class BestOfResult
{
    /** @param array<string, float> $subjects chosen subject => percentage */
    public function __construct(
        public readonly array $subjects,
        public readonly float $average,
        public readonly bool $complete,
    ) {}
}
