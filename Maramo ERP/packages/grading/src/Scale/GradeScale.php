<?php

declare(strict_types=1);

namespace Classivo\Grading\Scale;

use Classivo\Grading\InvalidConfiguration;

/**
 * An ordered set of bands and the rule for looking a percentage up in them.
 *
 * Gap policy:
 *  - THRESHOLD (default): a percentage belongs to the band with the highest min that is
 *    <= the percentage. Published board tables use whole numbers (33-40, 41-50), so
 *    40.5% would otherwise fall into a gap; THRESHOLD puts it in the 33-40 band.
 *  - STRICT: the percentage must lie inside [min, max] of a band, else no band.
 *    This mirrors the behaviour of the original Classivo Grade::forPercentage().
 */
final class GradeScale
{
    public const THRESHOLD = 'threshold';

    public const STRICT = 'strict';

    /** @var list<Band> highest min first */
    private array $bands;

    /** @param iterable<Band> $bands */
    public function __construct(iterable $bands, public readonly string $gapPolicy = self::THRESHOLD)
    {
        if (! in_array($gapPolicy, [self::THRESHOLD, self::STRICT], true)) {
            throw new InvalidConfiguration("Unknown gap policy [{$gapPolicy}].");
        }

        $list = [];
        $labels = [];
        foreach ($bands as $band) {
            if (! $band instanceof Band) {
                throw new InvalidConfiguration('GradeScale accepts Band instances only.');
            }
            if (isset($labels[$band->label])) {
                throw new InvalidConfiguration("Duplicate band label [{$band->label}].");
            }
            $labels[$band->label] = true;
            $list[] = $band;
        }
        if ($list === []) {
            throw new InvalidConfiguration('A grade scale needs at least one band.');
        }

        usort($list, static fn (Band $a, Band $b) => $b->min <=> $a->min);

        for ($i = 1, $n = count($list); $i < $n; $i++) {
            if ($list[$i]->min === $list[$i - 1]->min) {
                throw new InvalidConfiguration("Bands [{$list[$i - 1]->label}] and [{$list[$i]->label}] share the same min.");
            }
            if ($list[$i]->max > $list[$i - 1]->min) { // touching edges (40 | 40) are allowed
                throw new InvalidConfiguration("Bands [{$list[$i - 1]->label}] and [{$list[$i]->label}] overlap.");
            }
        }

        $this->bands = $list;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fromArray(array $rows, string $gapPolicy = self::THRESHOLD): self
    {
        return new self(array_map(static fn (array $row) => Band::fromArray($row), $rows), $gapPolicy);
    }

    public function find(float $percent): ?Band
    {
        foreach ($this->bands as $band) {
            if ($this->gapPolicy === self::STRICT) {
                if ($band->contains($percent)) {
                    return $band;
                }

                continue;
            }
            if ($percent >= $band->min) {
                return $band;
            }
        }

        return null;
    }

    public function byLabel(string $label): ?Band
    {
        foreach ($this->bands as $band) {
            if ($band->label === $label) {
                return $band;
            }
        }

        return null;
    }

    /** @return list<Band> highest band first */
    public function bands(): array
    {
        return $this->bands;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_map(static fn (Band $b) => $b->label, $this->bands);
    }

    /** Lowest min among bands not flagged as fail, or null when every band is a fail band. */
    public function lowestPassingMin(): ?float
    {
        $min = null;
        foreach ($this->bands as $band) {
            if (! $band->isFail) {
                $min = $min === null ? $band->min : min($min, $band->min);
            }
        }

        return $min;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Band $b) => $b->toArray(), $this->bands);
    }
}
