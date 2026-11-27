<?php

declare(strict_types=1);

namespace Classivo\Grading\Schemes;

use Classivo\Grading\Scale\Band;

/** The outcome of grading one percentage under one scheme. */
final class GradeResult
{
    public function __construct(
        public readonly string $scheme,
        public readonly float $percentage,
        public readonly float $roundedPercentage,
        public readonly ?Band $band,
        public readonly bool $passed,
    ) {}

    public function label(): ?string
    {
        return $this->band?->label;
    }

    public function gradePoint(): ?float
    {
        return $this->band?->gradePoint;
    }

    public function remark(): ?string
    {
        return $this->band?->remark;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme,
            'percentage' => $this->percentage,
            'rounded_percentage' => $this->roundedPercentage,
            'grade' => $this->label(),
            'grade_point' => $this->gradePoint(),
            'remark' => $this->remark(),
            'passed' => $this->passed,
        ];
    }
}
