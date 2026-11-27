<?php

namespace Classivo\Timetable\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A substitution (assigned or pending) was cancelled. */
class SubstitutionCancelled
{
    use Dispatchable;

    public function __construct(
        public int $substitutionId,
        public int $schoolId,
        public string $date,
        public ?int $substituteTeacherId,
        public int $absentTeacherId,
    ) {}
}
