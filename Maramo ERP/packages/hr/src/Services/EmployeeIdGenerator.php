<?php

namespace Classivo\Hr\Services;

use Classivo\Hr\Models\Staff;

/**
 * EmployeeIdGenerator (spec §15 Staff "Staff ID — Auto-series option") — builds the
 * next employee_id from a prefix + a zero-padded running counter, unique per school.
 * Considers soft-deleted rows so ids are never reused.
 *
 * Mirrors {@see AdmissionNumberGenerator} but for staff; the prefix/width are fixed
 * defaults (EMP + 4 digits) here rather than a branch series.
 */
class EmployeeIdGenerator
{
    public const PREFIX = 'EMP';

    public const DIGITS = 4;

    public function next(?int $schoolId = null, string $prefix = self::PREFIX, int $digits = self::DIGITS): string
    {
        $schoolId ??= current_school_id();

        $max = Staff::withTrashed()
            ->where('school_id', $schoolId)
            ->where('employee_id', 'like', $prefix.'%')
            ->get(['employee_id'])
            ->map(function (Staff $staff) use ($prefix): int {
                $suffix = substr((string) $staff->employee_id, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max();

        return $prefix.str_pad((string) (((int) $max) + 1), max(1, $digits), '0', STR_PAD_LEFT);
    }
}
