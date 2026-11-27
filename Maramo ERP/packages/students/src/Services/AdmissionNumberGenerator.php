<?php

namespace Classivo\Students\Services;

use Classivo\Core\Models\Branch;
use Classivo\Students\Models\Student;

/**
 * Builds the next admission number from the branch series
 * (reg_no_prefix + zero-padded counter, reg_no_digits wide, from reg_no_start),
 * unique per school, and the next roll number within a class-section + session.
 *
 * {@see next()} locks the school's matching rows (lockForUpdate) — call it inside
 * the admission transaction so concurrent admissions cannot collide.
 */
class AdmissionNumberGenerator
{
    public function next(?Branch $branch = null, ?int $schoolId = null): string
    {
        return $this->build($branch, $schoolId, lock: true);
    }

    /** Non-locking preview for the admission form. */
    public function peek(?Branch $branch = null, ?int $schoolId = null): string
    {
        return $this->build($branch, $schoolId, lock: false);
    }

    /** Next roll (max + 1) within a class-section + session. */
    public function nextRoll(?int $classSectionId, ?int $sessionId): string
    {
        if ($classSectionId === null) {
            return '1';
        }

        $sessionId ??= current_session_id();

        $max = (int) Student::withTrashed()
            ->where('class_section_id', $classSectionId)
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->whereNotNull('roll_no')
            ->get(['roll_no'])
            ->map(fn (Student $s): int => (int) $s->roll_no)
            ->max();

        return (string) ($max + 1);
    }

    protected function build(?Branch $branch, ?int $schoolId, bool $lock): string
    {
        $branch ??= current_branch();
        $schoolId ??= $branch?->school_id ?? current_school_id();

        $prefix = (string) ($branch?->reg_no_prefix ?? 'ADM');
        $start = (int) ($branch?->reg_no_start ?? 1);
        $digits = max(1, (int) ($branch?->reg_no_digits ?? 4));

        $query = Student::withTrashed()
            ->where('school_id', $schoolId)
            ->where('admission_no', 'like', $prefix.'%');

        if ($lock) {
            $query->lockForUpdate();
        }

        // Soft-deleted rows count too, so a number is never reused.
        $max = $query->get(['admission_no'])
            ->map(function (Student $student) use ($prefix): int {
                $suffix = substr((string) $student->admission_no, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max();

        $counter = max($start, ((int) $max) + 1);

        return $prefix.str_pad((string) $counter, $digits, '0', STR_PAD_LEFT);
    }
}
