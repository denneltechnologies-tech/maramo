<?php

declare(strict_types=1);

namespace Classivo\Grading\Schemes;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Percent;
use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\GradeScale;

/**
 * The generic, fully configurable scheme: a band table + a pass percentage + a rounding
 * rule. Every board preset in this package is an instance of this class, and state
 * boards or schools outside India configure one from an array (see fromArray()).
 *
 * A result passes when the rounded percentage is >= the pass percentage AND the band
 * it lands in is not flagged as a fail band.
 */
final class PercentageScheme implements GradingScheme
{
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        private readonly GradeScale $scale,
        private readonly float $passPercentage,
        private readonly Rounding $rounding = new Rounding,
    ) {
        if ($key === '') {
            throw new InvalidConfiguration('A scheme needs a key.');
        }
        if ($passPercentage < 0 || $passPercentage > 100) {
            throw new InvalidConfiguration('Pass percentage must be within 0..100.');
        }
    }

    /**
     * @param  array{key: string, name?: string, pass_percentage?: float|int, rounding?: array{mode?: string, precision?: int}|null, gap_policy?: string, bands: list<array<string, mixed>>}  $config
     */
    public static function fromArray(array $config): self
    {
        if (empty($config['key'])) {
            throw new InvalidConfiguration('Scheme config needs a [key].');
        }
        if (empty($config['bands']) || ! is_array($config['bands'])) {
            throw new InvalidConfiguration("Scheme [{$config['key']}] needs a non-empty [bands] list.");
        }

        $scale = GradeScale::fromArray($config['bands'], $config['gap_policy'] ?? GradeScale::THRESHOLD);
        $pass = $config['pass_percentage'] ?? $scale->lowestPassingMin() ?? 0.0;

        return new self(
            (string) $config['key'],
            (string) ($config['name'] ?? $config['key']),
            $scale,
            (float) $pass,
            Rounding::fromArray($config['rounding'] ?? null),
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function scale(): GradeScale
    {
        return $this->scale;
    }

    public function passPercentage(): float
    {
        return $this->passPercentage;
    }

    public function rounding(): Rounding
    {
        return $this->rounding;
    }

    public function grade(float $percentage): GradeResult
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new InvalidConfiguration("Percentage {$percentage} is outside 0..100.");
        }

        $rounded = $this->rounding->apply($percentage);
        $band = $this->scale->find($rounded);
        $passed = $rounded >= $this->passPercentage && ! ($band?->isFail ?? false);

        return new GradeResult($this->key, $percentage, $rounded, $band, $passed);
    }

    public function gradeMarks(float $obtained, float $max): GradeResult
    {
        if ($obtained < 0 || $obtained > $max) {
            throw new InvalidConfiguration("Marks {$obtained} are outside 0..{$max}.");
        }

        return $this->grade(Percent::of($obtained, $max));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'pass_percentage' => $this->passPercentage,
            'rounding' => $this->rounding->toArray(),
            'gap_policy' => $this->scale->gapPolicy,
            'bands' => $this->scale->toArray(),
        ];
    }
}
