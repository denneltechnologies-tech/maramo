<?php

declare(strict_types=1);

namespace Classivo\Grading\Assessment;

use Classivo\Grading\InvalidConfiguration;

/**
 * One weighted part of a subject's assessment, e.g. "Periodic tests" worth 10 marks of
 * 100, "Internal assessment" worth 20, "Half-yearly exam" worth 80.
 *
 * $weight is the number of marks this component contributes to the subject total; the
 * subject total is the sum of weights (usually 100, but anything > 0 works).
 *
 * Absence: when $absentAsZero is true an absent attempt counts as 0; when false it is
 * dropped (e.g. a medically excused periodic test) — but if every attempt is absent the
 * component still scores 0.
 */
final class Component
{
    public function __construct(
        public readonly string $key,
        public readonly float $weight,
        public readonly Reduction $reduction = new Reduction,
        public readonly bool $absentAsZero = true,
        public readonly ?string $label = null,
    ) {
        if ($key === '') {
            throw new InvalidConfiguration('A component needs a key.');
        }
        if ($weight <= 0) {
            throw new InvalidConfiguration("Component [{$key}] needs a positive weight.");
        }
    }

    /** @param array{key: string, weight: float|int, reduction?: string, n?: int|null, absent_as_zero?: bool, label?: string|null} $config */
    public static function fromArray(array $config): self
    {
        return new self(
            (string) $config['key'],
            (float) $config['weight'],
            new Reduction($config['reduction'] ?? Reduction::AVERAGE, isset($config['n']) ? (int) $config['n'] : null),
            (bool) ($config['absent_as_zero'] ?? true),
            $config['label'] ?? null,
        );
    }

    /**
     * Weighted marks this component contributes (0..weight).
     *
     * @param  list<Score>  $attempts
     */
    public function contribution(array $attempts): float
    {
        $usable = $this->absentAsZero
            ? $attempts
            : array_values(array_filter($attempts, static fn (Score $s) => ! $s->isAbsent()));

        return $this->reduction->reduce($usable) * $this->weight;
    }
}
