<?php

namespace Classivo\Fees\Services\Fees;

use Carbon\CarbonImmutable;
use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Filament\Pages\FeesReminder;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\SchoolTimezone;
use Classivo\Students\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FeeDefaulterQuery — "who is overdue, by how much, and since when", in SQL.
 *
 * ── WHAT THIS REPLACES ───────────────────────────────────────────────────────
 * A naive reminder board loads the WHOLE
 * `fee_student_allocations` table into PHP, filtered it with `isOverdue()` per
 * row, grouped it in a Collection and summed it in a closure. That is one full
 * table read per call — and the page CALLED IT INSIDE ITS OWN SEND LOOP, once
 * per defaulter, so "remind all" for 300 defaulters re-read the table 301 times.
 * On a 3,000-student tenant carrying 12 monthly allocations each — 36,000 rows —
 * that is 36,000 hydrated Eloquent models per defaulter.
 *
 * Everything this class answers is answered by ONE grouped aggregate over an
 * index, plus two small keyed reads per chunk. Nothing is hydrated that is not
 * going to be messaged.
 *
 * ── THE BALANCE EXPRESSION IS NOT REDEFINED HERE ─────────────────────────────
 * {@see balanceExpression()} is the package's ONE definition of "what this
 * allocation still owes" in SQL, floored at zero. A second, hand-written copy
 * of that expression is how the reminder total and the collection screen come to
 * disagree about what a parent owes, and a parent who is chased for a figure the
 * counter cannot collect is the worst outcome this module has. Only
 * balanceExpression() is used; the trait's request-shaped helpers are not.
 *
 * ── WHY `status` IS IN THE WHERE CLAUSE AND `balance` IS IN THE HAVING ────────
 * `status` is derived from the same figures by {@see FeeStudentAllocation::syncStatus()},
 * so `status IN ('unpaid','partial')` is a guaranteed SUPERSET of "still owes" —
 * safe to pre-filter on, and indexable (`fee_alloc_status_due_idx`, and its
 * school-pinned twin added with the ladder columns). The exact, authoritative test
 * is the GREATEST() expression, and it runs in the HAVING over the already-narrowed
 * set. A stale `status` column can therefore only ever cost a row that is filtered
 * out a moment later; it can never let a settled debt be chased.
 *
 * ── `whereDate()` IS NEVER USED ──────────────────────────────────────────────
 * `whereDate('due_date', '<', $x)` compiles to `DATE(due_date) < ?`, which wraps
 * the column in a function and makes every index on it unusable — the reason the
 * existing indexes bought the defaulters board nothing. `due_date` is a DATE
 * column, so a plain string comparison is both correct and sargable.
 */
class FeeDefaulterQuery
{
    /** Outstanding balance of one allocation row, floored at zero (portable SQL). */
    public function balanceExpression(string $table = 'fee_student_allocations'): string
    {
        $raw = "({$table}.amount - {$table}.discount_amount + {$table}.fine_amount - {$table}.paid_amount)";

        return "(CASE WHEN {$raw} > 0 THEN {$raw} ELSE 0 END)";
    }

    /** Statuses that can still owe money. A superset — see the class docblock. */
    private const OWING_STATUSES = ['unpaid', 'partial'];

    /**
     * One defaulter, with the Student already hydrated.
     *
     * The shape keeps every key {@see FeesReminder}'s Blade view
     * already reads (`student_id`, `name`, `admission_no`, `class_section`, `due`)
     * so the view needed no change, and adds what the ladder needs on top.
     *
     * @return object{
     *     student_id: int, school_id: int, branch_id: int, name: string,
     *     admission_no: string, class_section: string, due: float,
     *     allocations: int, oldest_due_date: ?CarbonImmutable, days_overdue: int,
     *     ladder_allocation_id: ?int, reminder_stage: ?int,
     *     last_reminded_at: ?CarbonImmutable, student: ?Student
     * }
     */
    private function row(object $aggregate, ?object $ladder, ?Student $student, CarbonImmutable $asOf): object
    {
        $oldest = $aggregate->oldest_due_date === null
            ? null
            : CarbonImmutable::parse($aggregate->oldest_due_date)->startOfDay();

        // *** BOTH SIDES OF THIS SUBTRACTION MUST BE CALENDAR DATES ***
        // `$asOf` is a school-WALL-CLOCK instant (SchoolTimezone::today() is
        // midnight in Asia/Kolkata, i.e. 18:30 UTC the previous day) while a
        // due_date parses to midnight UTC. Subtracting one from the other leaves a
        // 5h30m remainder that (int) truncates AWAY, so a debt 7 days old reported
        // 6 — every rung of the ladder fired a day late, and the 3-day rung fired
        // on day 4. Re-parsing the school's calendar DATE puts both operands on
        // the same footing and makes the difference exact whole days.
        $today = CarbonImmutable::parse($asOf->toDateString());

        return (object) [
            'student_id' => (int) $aggregate->student_id,
            'school_id' => (int) ($ladder?->school_id ?? $student?->school_id ?? 0),
            'branch_id' => (int) ($ladder?->branch_id ?? $student?->branch_id ?? 0),
            'name' => $student !== null ? ($student->full_name ?: trim($student->first_name.' '.$student->last_name)) : '—',
            'admission_no' => $student?->admission_no ?? '—',
            'class_section' => $this->className($student),
            'due' => round((float) $aggregate->overdue, 2),
            'allocations' => (int) $aggregate->allocations,
            'oldest_due_date' => $oldest,
            // Whole days, floored at zero. Measured from the OLDEST unpaid overdue
            // due date, which is what makes the rung "how long this student has been
            // in arrears" rather than "how long the newest bill has been out".
            'days_overdue' => $oldest === null ? 0 : max(0, (int) $oldest->diffInDays($today, false)),
            'ladder_allocation_id' => $ladder === null ? null : (int) $ladder->id,
            'reminder_stage' => $ladder?->reminder_stage === null ? null : (int) $ladder->reminder_stage,
            'last_reminded_at' => $ladder?->last_reminded_at === null
                ? null
                : CarbonImmutable::parse($ladder->last_reminded_at),
            'student' => $student,
        ];
    }

    /**
     * Every defaulter in the CURRENT panel context, newest debt first.
     *
     * Used by the Fees Reminder page, where the global tenant / branch / session
     * scopes are resolved and pin the set exactly as they always did. Bounded by
     * `$limit` because this one is rendered into a table: a page is a screen, not
     * a sweep, and the sweep is {@see chunk()}.
     *
     * @return Collection<int, object>
     */
    public function page(int $limit = 500, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= SchoolTimezone::today();

        $aggregates = $this->aggregate($asOf)
            ->orderByDesc('overdue')
            ->limit($limit)
            ->get();

        return $this->hydrate($aggregates, $asOf);
    }

    /**
     * Walk every defaulter across every school, in bounded chunks.
     *
     * *** KEYSET, NOT OFFSET ***
     * `chunkById()` cannot page a GROUP BY, and `LIMIT/OFFSET` over one re-runs
     * and re-sorts the whole aggregate for every page — the classic O(n²) sweep.
     * The group key IS the student id, so paging on `student_id > $cursor` is a
     * plain range scan that never revisits a row and cannot skip or duplicate a
     * student when one is paid off mid-sweep.
     *
     * *** THERE IS NO STALENESS FLOOR IN THIS QUERY, AND THAT IS THE FIX ***
     * This used to take a `$maxDaysOverdue` and translate it into a
     * `due_date >= today − N` floor on the AGGREGATE, so that an install whose
     * scheduler had never run did not drag years of settled-in-practice debt
     * through the sweep. It was a performance argument, and it cost correctness in
     * three separate ways — all of them live, all of them reaching guardians:
     *
     *   • `SUM(balance)` and `MIN(due_date)` were then computed over the WINDOWED
     *     rows, while the defaulters board and an API client (which have no floor)
     *     computed them over all of them. Student 448 was 149 days and ₹52,300 on
     *     the board and 119 days and ₹25,800 in the message his guardian received.
     *     Twelve school-4 students were understated by ₹6,050–₹26,500 each. The
     *     class docblock's own rule — "a parent who is chased for a figure the
     *     counter cannot collect is the worst outcome this module has" — was being
     *     broken by this method.
     *   • {@see ladderRows()} had NO floor, so the marker was stamped on the
     *     genuinely oldest allocation while the figures described a different,
     *     newer set of rows. The two halves of one decision disagreed about which
     *     debt was being announced.
     *   • The floor and {@see FeeReminderLadder::MAX_DAYS_OVERDUE} were the SAME
     *     number, so a genuinely stale row was always removed before `isStale()`
     *     could see it. `Skipped: stale` was structurally always 0, and a student
     *     whose only debts were older than the ceiling was dropped from the sweep
     *     entirely — never counted, never logged, invisible.
     *
     * So the ceiling now lives in exactly one place, {@see FeeReminderLadder::isStale()},
     * where it is a DECISION the sweep reports rather than a WHERE clause that
     * silently reshapes the money. The cost is a wider range scan on an index the
     * query already uses; the set is still narrowed by `status IN (…)` and by the
     * active-student EXISTS, and correctness is not worth trading for it here.
     *
     * @param  callable(Collection<int, object>): void  $callback
     * @param  int|null  $schoolId  pin to one tenant; null sweeps them all
     * @return int defaulters visited
     */
    public function chunk(
        callable $callback,
        int $size = 200,
        ?int $schoolId = null,
        ?CarbonImmutable $asOf = null,
    ): int {
        $asOf ??= SchoolTimezone::today();
        $size = max(1, $size);
        $cursor = 0;
        $visited = 0;

        do {
            $aggregates = $this->aggregate($asOf, $schoolId)
                ->having('student_id', '>', $cursor)
                ->orderBy('student_id')
                ->limit($size)
                ->get();

            if ($aggregates->isEmpty()) {
                break;
            }

            $cursor = (int) $aggregates->last()->student_id;
            $visited += $aggregates->count();

            $rows = $this->hydrate($aggregates, $asOf);

            if ($rows->isNotEmpty()) {
                $callback($rows);
            }
        } while ($aggregates->count() === $size);

        return $visited;
    }

    /**
     * The aggregate itself: one row per student who is overdue and still owes.
     *
     * `student_id > ?` is expressed as a HAVING rather than a WHERE so it survives
     * the GROUP BY on every driver — on the grouping column the two are equivalent
     * for the optimiser, and MySQL still resolves the range through the index.
     */
    private function aggregate(CarbonImmutable $asOf, ?int $schoolId = null): Builder
    {
        $balance = $this->balanceExpression();
        $today = $asOf->toDateString();

        return FeeStudentAllocation::query()
            // Explicit rather than implicit, exactly as NotificationSetting::scopedQuery()
            // argues: the global TenantScope is a no-op in the console by design, so a
            // sweep that relied on it would read every school at once and file one
            // school's reminders under another. Naming the school pins it identically
            // in web and console.
            ->when($schoolId !== null, fn (Builder $q) => $q->where('fee_student_allocations.school_id', $schoolId))
            ->whereIn('fee_student_allocations.status', self::OWING_STATUSES)
            ->whereNotNull('fee_student_allocations.due_date')
            ->where('fee_student_allocations.due_date', '<', $today)
            // A student who has left is not chased by a machine. Same rule
            // SendLowAttendanceAlerts applies to its own audience.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('students')
                ->whereColumn('students.id', 'fee_student_allocations.student_id')
                ->where('students.status', 'active')
                ->whereNull('students.deleted_at'))
            ->groupBy('fee_student_allocations.student_id')
            ->select('fee_student_allocations.student_id')
            ->selectRaw("SUM({$balance}) AS overdue")
            ->selectRaw("SUM(CASE WHEN {$balance} > 0 THEN 1 ELSE 0 END) AS allocations")
            ->selectRaw("MIN(CASE WHEN {$balance} > 0 THEN fee_student_allocations.due_date END) AS oldest_due_date")
            ->havingRaw("SUM({$balance}) > 0");
    }

    /**
     * Attach the Student and the ladder markers to a page of aggregates.
     *
     * TWO queries for the whole chunk, whatever its size:
     *   • the students, with every relation the message needs eager-loaded, so
     *     rendering a reminder issues no query of its own; and
     *   • the ladder rows — the oldest unpaid overdue allocation per student,
     *     which is the row that carries `reminder_stage` / `last_reminded_at`.
     *
     * The ladder row cannot come out of the aggregate above: SQL's MIN() picks a
     * VALUE, not the row it came from, and the portable ways of asking for "the
     * row holding the minimum" are a window function or a correlated subquery per
     * student. One extra indexed read over an already-narrow set is cheaper and
     * far easier to read than either.
     *
     * @param  Collection<int, object>  $aggregates
     * @return Collection<int, object>
     */
    private function hydrate(Collection $aggregates, CarbonImmutable $asOf): Collection
    {
        $ids = $aggregates->pluck('student_id')->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return collect();
        }

        $students = Student::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ladders = $this->ladderRows($ids, $asOf);

        return $aggregates
            ->map(fn (object $aggregate): object => $this->row(
                $aggregate,
                $ladders->get((int) $aggregate->student_id),
                $students->get((int) $aggregate->student_id),
                $asOf,
            ))
            ->values();
    }

    /**
     * Each student's ladder state, read across their WHOLE arrears cycle.
     *
     * Two different questions are answered here and they have different scopes:
     *
     *   • WHICH ROW the cycle is anchored to — the oldest unpaid overdue one,
     *     tie-broken by id so the choice is stable across runs. That is what
     *     `days_overdue` is measured from and which school/branch the reminder is
     *     filed under.
     *   • WHETHER THE CYCLE HAS ALREADY BEEN ANNOUNCED — the HIGHEST
     *     `reminder_stage` and the LATEST `last_reminded_at` across every unpaid
     *     overdue row the student has, not just the anchor's.
     *
     * *** WHY THE MARKERS ARE READ ACROSS THE CYCLE AND NOT OFF THE ANCHOR ***
     * They used to be read off the anchor alone, which made "already announced"
     * a property of ONE ROW. A student with several same-dated lines is one arrears
     * cycle but several rows: settle the anchor and the next row becomes the anchor
     * carrying NULL markers, `isClear()` says yes, and the guardian is sent the
     * identical "URGENT, 15 days overdue" message the day after they paid — once
     * per line. {@see FeeReminderLadder::stampCycle()} now writes the stamp across
     * every row in the cycle and this reads it back the same way, so the two halves
     * describe the same thing: a STUDENT at a RUNG, not a row.
     *
     * Global scopes are stripped and every clause is explicit. In the console the
     * scopes are inert anyway; on the web the SessionScope would otherwise hide the
     * very row the aggregate above counted whenever a debt outlives its session pin,
     * and a ladder whose marker row is invisible re-sends its rung every single day.
     *
     * @param  list<int>  $studentIds
     * @return Collection<int, object> keyed by student id
     */
    private function className(?Student $student): string
    {
        if ($student === null || empty($student->class_id)) {
            return '—';
        }

        static $names = [];

        return $names[$student->class_id] ??= (string) (SchoolClass::withoutGlobalScopes()->whereKey($student->class_id)->value('name') ?? '—');
    }

    private function ladderRows(array $studentIds, CarbonImmutable $asOf): Collection
    {
        $balance = $this->balanceExpression();

        return FeeStudentAllocation::query()
            ->withoutGlobalScopes()
            ->whereNull('fee_student_allocations.deleted_at')
            ->whereIn('fee_student_allocations.student_id', $studentIds)
            ->whereIn('fee_student_allocations.status', self::OWING_STATUSES)
            ->whereNotNull('fee_student_allocations.due_date')
            ->where('fee_student_allocations.due_date', '<', $asOf->toDateString())
            ->whereRaw("{$balance} > 0")
            ->orderBy('fee_student_allocations.student_id')
            ->orderBy('fee_student_allocations.due_date')
            ->orderBy('fee_student_allocations.id')
            ->get([
                'id',
                'school_id',
                'branch_id',
                'student_id',
                'due_date',
                'reminder_stage',
                'last_reminded_at',
            ])
            ->groupBy('student_id')
            ->mapWithKeys(function (Collection $group, $studentId): array {
                /** @var FeeStudentAllocation $anchor */
                $anchor = $group->first();

                $stages = $group->pluck('reminder_stage')->filter(fn ($stage): bool => $stage !== null);
                $reminded = $group->pluck('last_reminded_at')->filter();

                return [
                    (int) $studentId => (object) [
                        'id' => $anchor->id,
                        'school_id' => $anchor->school_id,
                        'branch_id' => $anchor->branch_id,
                        'due_date' => $anchor->due_date,
                        // The cycle's high-water marks — see the docblock.
                        'reminder_stage' => $stages->isEmpty() ? null : (int) $stages->max(),
                        'last_reminded_at' => $reminded->isEmpty()
                            ? null
                            : $reminded->sort()->last(),
                    ],
                ];
            });
    }
}
