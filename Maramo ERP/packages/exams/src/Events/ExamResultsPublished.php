<?php

namespace Classivo\Exams\Events;

use Classivo\Exams\Models\Exam;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after an exam's results were published. Report-card packages listen to this
 * to render marksheets; notification add-ons listen to tell students and guardians.
 */
class ExamResultsPublished
{
    use Dispatchable;

    public function __construct(public readonly Exam $exam) {}
}
