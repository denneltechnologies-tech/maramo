<?php

namespace Classivo\ReportCards\DTO;

final readonly class MarksheetData
{
    /**
     * @param  list<SubjectResult>  $subjects
     * @param  array<string,string>  $coScholastic  area => grade (optional, CBSE-style)
     */
    public function __construct(
        public Institution $institution,
        public StudentInfo $student,
        public string $examName,
        public array $subjects,
        public ?float $total = null,
        public ?float $totalFull = null,
        public ?float $percentage = null,
        public ?string $grade = null,
        public ?string $division = null,
        public ?int $rank = null,
        /** 'pass' | 'fail' | null (derived from the subjects when null). */
        public ?string $result = null,
        public ?string $remarks = null,
        public ?int $workingDays = null,
        public ?int $daysPresent = null,
        public array $coScholastic = [],
        public ?string $generatedOn = null,
    ) {}

    /** Fill total / percentage / result from the subject lines when not given. */
    public function withComputedTotals(): self
    {
        $total = $this->total ?? array_sum(array_map(fn (SubjectResult $s) => $s->obtained ?? 0.0, $this->subjects));
        $full = $this->totalFull ?? array_sum(array_map(fn (SubjectResult $s) => $s->fullMarks, $this->subjects));
        $pct = $this->percentage ?? ($full > 0 ? round($total / $full * 100, 2) : null);
        $result = $this->result ?? (count($this->subjects) && ! array_filter($this->subjects, fn (SubjectResult $s) => ! $s->passed()) ? 'pass' : 'fail');

        return new self(
            $this->institution, $this->student, $this->examName, $this->subjects,
            (float) $total, (float) $full, $pct, $this->grade, $this->division, $this->rank, $result,
            $this->remarks, $this->workingDays, $this->daysPresent, $this->coScholastic, $this->generatedOn,
        );
    }
}
