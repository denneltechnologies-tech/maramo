<?php

declare(strict_types=1);

namespace Classivo\Grading\Assessment;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Percent;

/** Marks obtained out of a maximum; obtained = null means the student was absent. */
final class Score
{
    public function __construct(
        public readonly ?float $obtained,
        public readonly float $max,
    ) {
        if ($max <= 0) {
            throw new InvalidConfiguration('Maximum marks must be greater than zero.');
        }
        if ($obtained !== null && ($obtained < 0 || $obtained > $max)) {
            throw new InvalidConfiguration("Marks {$obtained} are outside 0..{$max}.");
        }
    }

    public static function absent(float $max): self
    {
        return new self(null, $max);
    }

    /** Accepts a Score, [obtained, max], ['obtained' => x, 'max' => y] or a bare number (out of $defaultMax). */
    public static function from(mixed $value, ?float $defaultMax = null): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_array($value)) {
            $obtained = array_key_exists('obtained', $value) ? $value['obtained'] : ($value[0] ?? null);
            $max = $value['max'] ?? $value[1] ?? $defaultMax;
            if ($max === null) {
                throw new InvalidConfiguration('A score needs a maximum.');
            }

            return new self($obtained === null ? null : (float) $obtained, (float) $max);
        }
        if ($defaultMax === null) {
            throw new InvalidConfiguration('A bare score needs a default maximum.');
        }

        return new self($value === null ? null : (float) $value, $defaultMax);
    }

    public function isAbsent(): bool
    {
        return $this->obtained === null;
    }

    /** Fraction 0..1; absent counts as 0. */
    public function ratio(): float
    {
        return ($this->obtained ?? 0.0) / $this->max;
    }

    public function percent(): float
    {
        return Percent::of($this->obtained ?? 0.0, $this->max);
    }
}
