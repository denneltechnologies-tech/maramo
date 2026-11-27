<?php

namespace Classivo\Timetable\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A substitute was assigned to cover a period. A communication module can
 * listen to notify the substitute (and the class). Plain scalars only.
 */
class SubstitutionAssigned
{
    use Dispatchable;

    public function __construct(
        public int $substitutionId,
        public int $schoolId,
        public string $date,
        public int $substituteTeacherId,
        public int $absentTeacherId,
        public int $classSectionId,
        public int $periodNo,
        public string $startTime,
        public string $endTime,
        public ?int $subjectId,
        public ?string $room,
    ) {}
}
