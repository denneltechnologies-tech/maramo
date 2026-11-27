<?php

declare(strict_types=1);

namespace Classivo\Grading\Pass;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Percent;
use Classivo\Grading\Rounding;

/**
 * Pass criterion for one subject: a minimum overall percentage, plus optional minimum
 * percentages per part (e.g. CBSE: 33% overall and 33% separately in theory and in
 * practical). Percentages are rounded with $rounding before comparison.
 *
 * $markStep is the granularity of marks (1 = whole marks) used when computing how many
 * marks a student is short by, which is what grace marks are measured against.
 */
final class PassRule
{
    /** @param array<string, float> $partMinimums part => minimum percent */
    public function __construct(
        public readonly float $overallMinimum,
        public readonly array $partMinimums = [],
        public readonly Rounding $rounding = new Rounding,
        public readonly float $markStep = 1.0,
    ) {
        if ($overallMinimum < 0 || $overallMinimum > 100) {
            throw new InvalidConfiguration('Overall minimum must be within 0..100.');
        }
        foreach ($partMinimums as $part => $min) {
            if ($min < 0 || $min > 100) {
                throw new InvalidConfiguration("Minimum for part [{$part}] must be within 0..100.");
            }
        }
    }

    /** @param array{overall: float|int, parts?: array<string, float|int>, rounding?: array<string, mixed>|null, mark_step?: float|int} $config */
    public static function fromArray(array $config): self
    {
        return new self(
            (float) $config['overall'],
            array_map('floatval', $config['parts'] ?? []),
            Rounding::fromArray($config['rounding'] ?? null),
            (float) ($config['mark_step'] ?? 1.0),
        );
    }

    public function evaluate(SubjectMarks $marks): SubjectOutcome
    {
        $failedParts = [];
        $shortfalls = [];
        $patched = $marks;

        foreach ($this->partMinimums as $part => $minimum) {
            if (! isset($marks->parts[$part])) {
                continue; // the subject has no such part (e.g. no practical)
            }
            $score = $marks->parts[$part];
            if ($this->rounding->apply($score->percent()) < $minimum) {
                $failedParts[] = $part;
                $need = max(0.0, $this->marksNeeded($minimum, $score->max) - ($score->obtained ?? 0.0));
                $shortfalls[$part] = $need;
                $patched = $patched->withAdded($part, $need);
            }
        }

        $overallPassed = $this->rounding->apply($marks->percentage()) >= $this->overallMinimum;
        if (! $overallPassed) {
            $failedParts[] = 'overall';
        }

        // Extra marks needed for the overall minimum once part shortfalls are filled.
        $overallNeed = max(0.0, $this->marksNeeded($this->overallMinimum, $marks->max()) - $patched->obtained());
        if ($overallNeed > 0) {
            $shortfalls['overall'] = $overallNeed;
        }

        return new SubjectOutcome(
            $marks,
            $failedParts === [],
            array_values(array_unique($failedParts)),
            $shortfalls,
        );
    }

    /**
     * Smallest number of marks (multiple of markStep) whose rounded percentage reaches $minimum.
     */
    private function marksNeeded(float $minimum, float $max): float
    {
        if ($minimum <= 0) {
            return 0.0;
        }
        $step = $this->markStep > 0 ? $this->markStep : 1.0;
        $marks = max(0.0, Percent::marksFor($minimum, $max, $step) - 2 * $step);
        while ($marks <= $max) {
            if ($this->rounding->apply(Percent::of($marks, $max)) >= $minimum) {
                return $marks;
            }
            $marks = round($marks + $step, 9);
        }

        return $max;
    }
}
