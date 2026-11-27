<?php

declare(strict_types=1);

namespace Classivo\Grading\Pass;

use Classivo\Grading\InvalidConfiguration;

/**
 * Limits on grace marks. Boards and schools differ and most do not publish their
 * moderation rules, so everything is configurable and the default grants none.
 *
 *  - maxPerSubject:      most grace marks one subject may receive
 *  - maxTotal:           most grace marks across all subjects of one student
 *  - maxSubjects:        most subjects that may receive grace
 *  - allOrNothing:       true = only grant grace if it clears EVERY failing subject
 *                        (grace that leaves the student failing anyway is withheld)
 *  - maxPerSubjectPercent: optional cap as a percentage of the subject's maximum
 *                        (the tighter of the two caps applies)
 */
final class GracePolicy
{
    public function __construct(
        public readonly float $maxPerSubject = 0.0,
        public readonly float $maxTotal = 0.0,
        public readonly int $maxSubjects = 0,
        public readonly bool $allOrNothing = false,
        public readonly ?float $maxPerSubjectPercent = null,
    ) {
        if ($maxPerSubject < 0 || $maxTotal < 0 || $maxSubjects < 0) {
            throw new InvalidConfiguration('Grace limits cannot be negative.');
        }
        if ($maxPerSubjectPercent !== null && ($maxPerSubjectPercent < 0 || $maxPerSubjectPercent > 100)) {
            throw new InvalidConfiguration('maxPerSubjectPercent must be within 0..100.');
        }
    }

    public static function none(): self
    {
        return new self;
    }

    /** @param array<string, mixed>|null $config */
    public static function fromArray(?array $config): self
    {
        if ($config === null) {
            return self::none();
        }

        return new self(
            (float) ($config['max_per_subject'] ?? 0),
            (float) ($config['max_total'] ?? 0),
            (int) ($config['max_subjects'] ?? 0),
            (bool) ($config['all_or_nothing'] ?? false),
            isset($config['max_per_subject_percent']) ? (float) $config['max_per_subject_percent'] : null,
        );
    }

    public function capFor(float $subjectMax): float
    {
        $cap = $this->maxPerSubject;
        if ($this->maxPerSubjectPercent !== null) {
            $cap = min($cap, $subjectMax * $this->maxPerSubjectPercent / 100);
        }

        return $cap;
    }
}
