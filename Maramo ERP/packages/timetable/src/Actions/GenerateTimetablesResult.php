<?php

namespace Classivo\Timetable\Actions;

use Classivo\Timetable\Generator\Result;

/** What {@see GenerateTimetables} did: the generator result and the timetables it saved. */
final class GenerateTimetablesResult
{
    /** @param list<int> $timetableIds empty when nothing was saved */
    public function __construct(
        public readonly Result $result,
        public readonly array $timetableIds,
    ) {}

    public function saved(): bool
    {
        return $this->timetableIds !== [];
    }
}
