<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/**
 * A (day, period) cell of the weekly grid. `day` is whatever key the caller
 * used in {@see Problem::$days} (e.g. 1 = Monday); `period` is 1-based.
 */
final class Slot
{
    public function __construct(
        public readonly int|string $day,
        public readonly int $period,
    ) {
        if ($period < 1) {
            throw new InvalidProblem("Period numbers are 1-based, got {$period}.");
        }
    }

    /** @param Slot|array{0:int|string,1:int}|array{day:int|string,period:int} $value */
    public static function from(self|array $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (array_key_exists('day', $value)) {
            return new self($value['day'], (int) $value['period']);
        }

        return new self($value[0], (int) $value[1]);
    }

    public function __toString(): string
    {
        return "day {$this->day} period {$this->period}";
    }
}
