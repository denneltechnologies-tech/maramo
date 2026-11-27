<?php

namespace Classivo\Fees\Support;

use Classivo\Fees\Contracts\HolidayCalendar;

/** Default {@see HolidayCalendar}: no holidays known. */
class NullHolidayCalendar implements HolidayCalendar
{
    public function holidayRanges(int $schoolId, ?int $branchId, ?int $sessionId): iterable
    {
        return [];
    }
}
