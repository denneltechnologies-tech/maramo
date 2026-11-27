<?php

declare(strict_types=1);

namespace Classivo\Grading\Pass;

final class SubjectOutcome
{
    /**
     * @param  list<string>  $failedParts  part names (and/or "overall") below their minimum
     * @param  array<string, float>  $shortfalls  part/"overall" => marks still needed
     */
    public function __construct(
        public readonly SubjectMarks $marks,
        public readonly bool $passed,
        public readonly array $failedParts,
        public readonly array $shortfalls,
        public readonly float $graceAwarded = 0.0,
    ) {}

    /** Total marks this subject is short of a pass. */
    public function shortfall(): float
    {
        return array_sum($this->shortfalls);
    }
}
