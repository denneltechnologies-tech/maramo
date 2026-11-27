<?php

namespace Classivo\Students\Services;

use Carbon\CarbonImmutable;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Students\Events\StudentsMarkedAbsent;
use Classivo\Students\Models\Attendance;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentSessionRecord;
use Classivo\Students\Models\SubjectAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The single code path for taking attendance and computing percentages.
 *
 * Percentage model:
 *   - Countable days = present / absent / late / half_day.
 *     Holiday and Leave are EXCLUDED from the denominator.
 *   - Credit: present = 1, late = 1, half_day = 0.5, absent = 0.
 *   - % = round(credit / countable * 100, 2); 0.0 with no countable days.
 */
class AttendanceService
{
    public const COUNTABLE = ['present', 'absent', 'late', 'half_day'];

    public const CREDIT = [
        'present' => 1.0,
        'late' => 1.0,
        'half_day' => 0.5,
        'absent' => 0.0,
    ];

    /**
     * Active students enrolled in a class-section for a session, ordered by roll.
     * Read from student_session_records so it follows promotions.
     *
     * @return Collection<int, Student>
     */
    public function roster(ClassSection $classSection, ?int $sessionId = null): Collection
    {
        $sessionId ??= current_session_id();

        return StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))
            ->where('class_section_id', $classSection->getKey())
            ->with('student')
            ->get()
            ->filter(fn (StudentSessionRecord $r): bool => $r->student && $r->student->status === 'active')
            ->sortBy(fn (StudentSessionRecord $r): array => [(int) $r->roll_no, (string) $r->roll_no])
            ->map(function (StudentSessionRecord $r): Student {
                $r->student->setAttribute('roster_roll_no', $r->roll_no);

                return $r->student;
            })
            ->values();
    }

    /** @return Collection<int, Attendance> keyed by student_id */
    public function existingDaily(int $classSectionId, string $date): Collection
    {
        return Attendance::query()
            ->where('class_section_id', $classSectionId)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('student_id');
    }

    /**
     * Ids of the students enrolled in the class-section for its own session. Rows
     * for any other student (another class, branch or school) are ignored when saving.
     *
     * @return array<int, true>
     */
    public function enrolledIds(ClassSection $classSection): array
    {
        return StudentSessionRecord::withoutGlobalScope(SessionScope::class)
            ->where('class_section_id', $classSection->getKey())
            ->where('session_id', $classSection->session_id)
            ->pluck('student_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Upsert daily attendance (unique per student + date, so re-saving edits
     * in place). Fires {@see StudentsMarkedAbsent} for absentees.
     *
     * @param  array<int, array<string, mixed>>  $rows  [student_id, status, remark?]
     */
    public function saveDaily(ClassSection $classSection, string $date, array $rows, ?int $markedBy = null, bool $notify = true): int
    {
        $count = 0;
        $absentIds = [];
        $enrolled = $this->enrolledIds($classSection);

        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);

            if ($studentId <= 0 || ! isset($enrolled[$studentId])) {
                continue;
            }

            $status = $row['status'] ?? 'present';

            $this->upsert(
                Attendance::withoutGlobalScopes()->where('student_id', $studentId)->whereDate('date', $date),
                new Attendance(['student_id' => $studentId, 'date' => $date]),
                [
                    'school_id' => $classSection->school_id,
                    'branch_id' => $classSection->branch_id,
                    'session_id' => $classSection->session_id,
                    'class_section_id' => $classSection->getKey(),
                    'status' => $status,
                    'remark' => $row['remark'] ?? null,
                    'marked_by' => $markedBy,
                    'deleted_at' => null,
                ],
            );

            if ($status === 'absent') {
                $absentIds[] = $studentId;
            }

            $count++;
        }

        if ($notify && $absentIds !== []) {
            StudentsMarkedAbsent::dispatch($classSection, $date, array_values(array_unique($absentIds)));
        }

        return $count;
    }

    /**
     * Upsert subject / period attendance (unique per student, subject, date, period).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function saveSubject(ClassSection $classSection, int $subjectId, string $date, ?int $periodNo, array $rows, ?int $markedBy = null): int
    {
        $count = 0;
        $enrolled = $this->enrolledIds($classSection);

        foreach ($rows as $row) {
            $studentId = (int) ($row['student_id'] ?? 0);

            if ($studentId <= 0 || ! isset($enrolled[$studentId])) {
                continue;
            }

            $this->upsert(
                SubjectAttendance::withoutGlobalScopes()->where('student_id', $studentId)->where('subject_id', $subjectId)
                    ->whereDate('date', $date)->where(fn ($q) => $periodNo === null ? $q->whereNull('period_no') : $q->where('period_no', $periodNo)),
                new SubjectAttendance(['student_id' => $studentId, 'subject_id' => $subjectId, 'date' => $date, 'period_no' => $periodNo]),
                [
                    'school_id' => $classSection->school_id,
                    'branch_id' => $classSection->branch_id,
                    'session_id' => $classSection->session_id,
                    'class_section_id' => $classSection->getKey(),
                    'status' => $row['status'] ?? 'present',
                    'remark' => $row['remark'] ?? null,
                    'marked_by' => $markedBy,
                    'deleted_at' => null,
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * Find-or-new + fill + save. Dates are matched with whereDate so the
     * lookup is identical on MySQL, PostgreSQL and SQLite.
     *
     * @param  array<string, mixed>  $values
     */
    private function upsert(Builder $query, Model $new, array $values): void
    {
        $model = $query->first() ?? $new;
        $model->forceFill($values)->save();
    }

    /** @return array<string, int> status => count, every status present */
    public function statusCounts(int $studentId, string $from, string $to): array
    {
        $counts = array_fill_keys(array_keys(Attendance::STATUSES), 0);

        $rows = Attendance::query()
            ->where('student_id', $studentId)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $aggregate) {
            $counts[$status] = (int) $aggregate;
        }

        return $counts;
    }

    public function percentage(int $studentId, string $from, string $to): float
    {
        return $this->summary($studentId, $from, $to)['percentage'];
    }

    /**
     * @return array{counts: array<string,int>, countable: int, present_credit: float, percentage: float}
     */
    public function summary(int $studentId, string $from, string $to): array
    {
        $counts = $this->statusCounts($studentId, $from, $to);

        $countable = 0;
        $credit = 0.0;

        foreach (self::COUNTABLE as $status) {
            $countable += $counts[$status];
            $credit += $counts[$status] * self::CREDIT[$status];
        }

        return [
            'counts' => $counts,
            'countable' => $countable,
            'present_credit' => $credit,
            'percentage' => $countable === 0 ? 0.0 : round($credit / $countable * 100, 2),
        ];
    }

    /** @return array<int, int> weekend weekday numbers (0=Sun .. 6=Sat) of a branch */
    public function weekendDaysFor(?int $branchId): array
    {
        if (! $branchId) {
            return [];
        }

        $branch = Branch::withoutGlobalScopes()->find($branchId);

        return collect($branch?->weekend_days ?? [])->map(fn ($d): int => (int) $d)->all();
    }

    public function isWeekend(?int $branchId, string $date): bool
    {
        return in_array(CarbonImmutable::parse($date)->dayOfWeek, $this->weekendDaysFor($branchId), true);
    }
}
