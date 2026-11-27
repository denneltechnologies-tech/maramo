<?php

declare(strict_types=1);

namespace Classivo\Grading\CoScholastic;

use Classivo\Grading\InvalidConfiguration;
use Classivo\Grading\Scale\GradeScale;

/**
 * A descriptive scale for co-scholastic areas (art, work education, health & physical
 * education, discipline, values...). Teachers usually pick the grade directly, so
 * validate() and describe() are the main entry points; if a percentage scale is
 * attached, fromPercentage() derives the grade from an activity score instead.
 */
final class CoScholasticScale
{
    /** @var array<string, ?string> label => descriptor, best first */
    private array $grades;

    /** @param array<string, ?string> $grades label => descriptor (null when the source gives none) */
    public function __construct(
        public readonly string $key,
        array $grades,
        private readonly ?GradeScale $percentScale = null,
    ) {
        if ($grades === []) {
            throw new InvalidConfiguration('A co-scholastic scale needs at least one grade.');
        }
        $this->grades = $grades;
        if ($percentScale !== null) {
            foreach ($percentScale->labels() as $label) {
                if (! array_key_exists($label, $grades)) {
                    throw new InvalidConfiguration("Percent band [{$label}] is not a grade of scale [{$key}].");
                }
            }
        }
    }

    /** @param array{key: string, grades: array<string, ?string>, bands?: list<array<string, mixed>>} $config */
    public static function fromArray(array $config): self
    {
        return new self(
            (string) $config['key'],
            $config['grades'],
            isset($config['bands']) ? GradeScale::fromArray($config['bands']) : null,
        );
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_map('strval', array_keys($this->grades));
    }

    public function isValid(string $label): bool
    {
        return array_key_exists($label, $this->grades);
    }

    public function validate(string $label): string
    {
        $normalised = trim($label);
        if (! $this->isValid($normalised)) {
            $normalised = strtoupper($normalised);
        }
        if (! $this->isValid($normalised)) {
            throw new InvalidConfiguration("[{$label}] is not a grade on scale [{$this->key}] (".implode(', ', $this->labels()).').');
        }

        return $normalised;
    }

    public function describe(string $label): ?string
    {
        return $this->grades[$this->validate($label)];
    }

    public function fromPercentage(float $percent): ?string
    {
        if ($this->percentScale === null) {
            throw new InvalidConfiguration("Scale [{$this->key}] has no percentage bands.");
        }

        return $this->percentScale->find($percent)?->label;
    }
}
