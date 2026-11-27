<?php

namespace Classivo\Students\Events;

use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a promotion / leave decision. `carryForwardFees` tells a fees
 * module whether the admin asked for dues to be carried into the new session.
 */
class StudentPromoted
{
    use Dispatchable;

    public function __construct(
        public Student $student,
        public int $fromSessionId,
        public ?int $toSessionId,
        public ?StudentSessionRecord $record,
        public bool $carryForwardFees = false,
    ) {}
}
