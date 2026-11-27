<?php

namespace Classivo\Hr\Services;

use Classivo\Core\Models\Branch;
use Classivo\Core\Support\Preset;

/** Which weekdays are non-working for a branch (0 = Sunday .. 6 = Saturday). */
class BranchCalendar
{
    /** @return array<int, int> */
    public function weekendDaysFor(?int $branchId): array
    {
        $days = null;

        if ($branchId) {
            $days = Branch::withoutGlobalScopes()->find($branchId)?->weekend_days;
        }

        $days ??= Preset::get('weekend_days', []);

        return collect($days)->map(fn ($d): int => (int) $d)->values()->all();
    }
}
