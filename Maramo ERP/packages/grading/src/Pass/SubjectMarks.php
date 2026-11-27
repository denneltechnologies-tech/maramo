<?php

declare(strict_types=1);

namespace Classivo\Grading\Pass;

use Classivo\Grading\Assessment\Score;
use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Percent;

/**
 * A subject's marks split into named parts (e.g. theory 70 + practical 30). A subject
 * with no split has one part, conventionally "total".
 */
final class SubjectMarks
{
    /** @var array<string, Score> */
    public readonly array $parts;

    /** @param array<string, Score|array{0: float|int|null, 1: float|int}|array{obtained: float|int|null, max: float|int}> $parts */
    public function __construct(array $parts)
    {
        if ($parts === []) {
            throw new InvalidConfiguration('A subject needs at least one part.');
        }
        $normalised = [];
        foreach ($parts as $name => $score) {
            $normalised[(string) $name] = Score::from($score);
        }
        $this->parts = $normalised;
    }

    public static function single(?float $obtained, float $max): self
    {
        return new self(['total' => new Score($obtained, $max)]);
    }

    public function obtained(): float
    {
        return array_sum(array_map(static fn (Score $s) => $s->obtained ?? 0.0, $this->parts));
    }

    public function max(): float
    {
        return array_sum(array_map(static fn (Score $s) => $s->max, $this->parts));
    }

    public function percentage(): float
    {
        return Percent::of($this->obtained(), $this->max());
    }

    /** A copy with $marks added to $part, capped at the part's maximum. */
    public function withAdded(string $part, float $marks): self
    {
        if (! isset($this->parts[$part])) {
            throw new InvalidConfiguration("Unknown part [{$part}].");
        }
        $parts = $this->parts;
        $old = $parts[$part];
        $parts[$part] = new Score(min($old->max, ($old->obtained ?? 0.0) + $marks), $old->max);

        return new self($parts);
    }
}
