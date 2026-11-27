<?php

declare(strict_types=1);

namespace Classivo\Grading\Pass;

final class StudentOutcome
{
    /** @param array<string, SubjectOutcome> $subjects */
    public function __construct(public readonly array $subjects) {}

    public function passed(): bool
    {
        foreach ($this->subjects as $outcome) {
            if (! $outcome->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function failedSubjects(): array
    {
        return array_keys(array_filter($this->subjects, static fn (SubjectOutcome $o) => ! $o->passed));
    }

    public function totalGrace(): float
    {
        return array_sum(array_map(static fn (SubjectOutcome $o) => $o->graceAwarded, $this->subjects));
    }

    /** @return array<string, float> subjects that received grace => marks */
    public function graceBySubject(): array
    {
        return array_filter(
            array_map(static fn (SubjectOutcome $o) => $o->graceAwarded, $this->subjects),
            static fn (float $g) => $g > 0,
        );
    }
}
