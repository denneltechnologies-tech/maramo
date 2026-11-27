<?php

namespace Classivo\Fees\Contracts;

/**
 * HolidayCalendar — the days a school was shut, so a DAILY late fine does not
 * run through a closure. The default binding returns no holidays; a calendar
 * module can bind an implementation that reads its own events table.
 */
interface HolidayCalendar
{
    /**
     * Holiday ranges for one school/branch/session.
     *
     * @return iterable<array{start: string, end: ?string}> Y-m-d dates; end null = one day
     */
    public function holidayRanges(int $schoolId, ?int $branchId, ?int $sessionId): iterable;
}
