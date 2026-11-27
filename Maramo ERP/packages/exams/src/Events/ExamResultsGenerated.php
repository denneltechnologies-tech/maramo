<?php

namespace Classivo\Exams\Events;

use Classivo\Core\Models\ClassSection;
use Classivo\Exams\Models\Exam;
use Classivo\Exams\Models\ExamResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/** Fired after results were (re)generated for one class-section of an exam. */
class ExamResultsGenerated
{
    use Dispatchable;

    /** @param  Collection<int, ExamResult>  $results */
    public function __construct(
        public readonly Exam $exam,
        public readonly ClassSection $classSection,
        public readonly Collection $results,
    ) {}
}
