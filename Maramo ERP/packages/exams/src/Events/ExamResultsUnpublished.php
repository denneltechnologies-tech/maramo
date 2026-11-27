<?php

namespace Classivo\Exams\Events;

use Classivo\Exams\Models\Exam;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after an exam's results were unpublished (marks entry unlocked again). */
class ExamResultsUnpublished
{
    use Dispatchable;

    public function __construct(public readonly Exam $exam) {}
}
