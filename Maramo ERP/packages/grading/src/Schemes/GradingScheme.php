<?php

declare(strict_types=1);

namespace Classivo\Grading\Schemes;

use Classivo\Grading\Rounding;
use Classivo\Grading\Scale\GradeScale;

/** A grading scheme turns a subject percentage into a grade, grade point and pass/fail. */
interface GradingScheme
{
    /** Stable machine key, e.g. "cbse.9-12" or "state.generic". */
    public function key(): string;

    public function name(): string;

    public function scale(): GradeScale;

    public function passPercentage(): float;

    public function rounding(): Rounding;

    public function grade(float $percentage): GradeResult;

    /** Marks out of $max convenience wrapper around grade(). */
    public function gradeMarks(float $obtained, float $max): GradeResult;
}
