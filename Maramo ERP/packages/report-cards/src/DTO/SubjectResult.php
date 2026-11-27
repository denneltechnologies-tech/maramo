<?php

namespace Classivo\ReportCards\DTO;

final readonly class SubjectResult
{
    public function __construct(
        public string $subject,
        public float $fullMarks,
        public float $passMarks,
        /** null when absent. */
        public ?float $obtained,
        public bool $absent = false,
        public ?string $grade = null,
        public ?float $gradePoints = null,
        public ?string $remarks = null,
    ) {}

    public function passed(): bool
    {
        return ! $this->absent && $this->obtained !== null && $this->obtained >= $this->passMarks;
    }
}
