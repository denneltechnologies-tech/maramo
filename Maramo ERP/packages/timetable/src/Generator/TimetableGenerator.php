<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Clash-free weekly timetable generator (pure PHP, no framework).
 *
 * Model: every lesson period is a variable whose value is a (day, period[,
 * room]) slot. Hard constraints: a class, a teacher and a room are never
 * double-booked; teachers are never placed in their unavailable slots; blocked
 * slots stay empty; a teacher never teaches more than `maxConsecutive` periods
 * back to back; optionally at most N periods of a subject per class per day.
 * Soft goals (value ordering): spread a subject across the week and avoid the
 * same subject twice in one day.
 *
 * Search: depth-first backtracking with
 *  - MRV: the lesson with the least slack (legal slots minus periods still to
 *    place) is expanded first; ties broken by teacher load, then a seeded
 *    random key — so the same seed always gives the same timetable;
 *  - forward checking: a branch dies as soon as any lesson, class or teacher
 *    has fewer free legal slots than periods still to place;
 *  - symmetry breaking: the periods of one lesson are interchangeable, so a
 *    slot that failed for a lesson is excluded for that lesson's remaining
 *    periods in the same subtree;
 *  - restarts: each attempt has a backtrack budget that doubles per restart
 *    (new tie-break keys from the seed). An attempt that exhausts its tree
 *    without hitting the budget is a proof of unsatisfiability.
 * A wall-clock limit always applies; on expiry you get the best partial
 * timetable and status TIMEOUT.
 */
final class TimetableGenerator
{
    private const DEAD = -1;

    private const DONE = -2;

    private const W_SAME_DAY = 300;

    private const W_ADJACENT_DAY = 20;

    private const W_CONTENTION = 100;

    /** @var list<list<int>> */
    private array $reqsByClass = [];

    /** @var array<int,list<int>> */
    private array $reqsByTeacher = [];

    // ---- static model ----------------------------------------------------

    private Problem $problem;

    private Options $options;

    private int $maxP;

    private int $dayCount;

    /** @var list<int> valid slot indexes (exist, not blocked) */
    private array $validSlots = [];

    /** @var array<int,int> slot => day index */
    private array $slotDay = [];

    /** @var list<Lesson> requirement index => lesson (periodsPerWeek > 0) */
    private array $reqLesson = [];

    /** @var list<int> */
    private array $reqClass = [];

    /** @var list<int> teacher index or -1 */
    private array $reqTeacher = [];

    /** @var list<int> class+subject index */
    private array $reqSubject = [];

    /** @var list<list<int>|null> candidate room indexes, null = no room */
    private array $reqRooms = [];

    /** @var list<float> */
    private array $reqTie = [];

    /** @var array<int,int> teacher index => total periods */
    private array $teacherLoad = [];

    /** @var list<bool> room index => referenced by a fixed-room lesson */
    private array $roomPinned = [];

    /** @var list<array<int,bool>> */
    private array $teacherUnavail = [];

    // ---- dynamic state ---------------------------------------------------

    /** @var list<int> */
    private array $remaining = [];

    /** @var list<array<int,bool>> */
    private array $classBusy = [];

    /** @var list<array<int,bool>> */
    private array $teacherBusy = [];

    /** @var list<array<int,bool>> */
    private array $roomBusy = [];

    /** @var list<array<int,int>> class+subject => day => count */
    private array $subjectDay = [];

    /** @var list<array<int,int>> req => slot => exclusion depth */
    private array $excluded = [];

    /** @var list<int> */
    private array $classRemaining = [];

    /** @var list<int> */
    private array $classFree = [];

    /** @var array<int,int> */
    private array $teacherRemaining = [];

    /** @var array<int,int> */
    private array $teacherFree = [];

    /** @var array<int,array{0:int,1:int,2:int}> current assignments: id => [req, slot, room] */
    private array $assigned = [];

    private int $nextAssignId = 0;

    /** @var list<int> */
    private array $wipeouts = [];

    public function solve(Problem $problem, ?Options $options = null): Result
    {
        $this->problem = $problem;
        $this->options = $options ?? new Options;
        $started = hrtime(true);

        $this->compile();
        $this->wipeouts = array_fill(0, count($this->reqLesson), 0);

        $reasons = $this->precheck();
        if ($reasons !== []) {
            return new Result(Result::UNSATISFIABLE, [], $reasons, [], ['elapsed_ms' => $this->ms($started)]);
        }

        $deadline = $started + (int) ($this->options->timeLimitSeconds * 1e9);
        $totalUnits = array_sum($this->remaining);
        $best = [];
        $nodes = 0;
        $backtracks = 0;
        $attempt = 0;

        while (true) {
            $this->resetState();
            $this->reseed($attempt);
            $budget = $this->options->restartBase * (2 ** min($attempt, 30));
            $outcome = $this->search($deadline, $budget, $nodes, $backtracks, $best, $totalUnits);

            if ($outcome === 'solved') {
                $placements = $this->placements($this->assigned);

                return new Result(Result::SOLVED, $placements, [], [], $this->stats($started, $nodes, $backtracks, $attempt, $placements));
            }

            if ($outcome === 'exhausted') {
                return new Result(
                    Result::UNSATISFIABLE,
                    [],
                    $this->exhaustedReasons(),
                    [],
                    $this->stats($started, $nodes, $backtracks, $attempt, []),
                );
            }

            if ($outcome === 'timeout') {
                $placements = $this->placements($best);

                return new Result(
                    Result::TIMEOUT,
                    $placements,
                    [sprintf('Time limit of %.1fs reached before a complete timetable was found; %d of %d periods placed.', $this->options->timeLimitSeconds, count($placements), $totalUnits)],
                    $this->unplaced($placements),
                    $this->stats($started, $nodes, $backtracks, $attempt, $placements),
                );
            }

            $attempt++; // budget hit → restart with fresh tie-breaks
        }
    }

    // ---------------------------------------------------------------------
    // Model compilation
    // ---------------------------------------------------------------------

    private function compile(): void
    {
        $p = $this->problem;
        $this->maxP = $p->maxPeriods();
        $this->dayCount = count($p->days);

        $blocked = [];
        foreach ($p->blockedSlots as $slot) {
            $blocked[$this->slotIndex($slot)] = true;
        }

        $this->validSlots = [];
        $this->slotDay = [];
        foreach ($p->days as $d => $day) {
            for ($period = 1; $period <= $p->periodsPerDay[$day]; $period++) {
                $s = $d * $this->maxP + $period - 1;
                $this->slotDay[$s] = $d;
                if (! isset($blocked[$s])) {
                    $this->validSlots[] = $s;
                }
            }
        }

        $classIndex = array_flip(array_map('strval', $p->classes));
        $teacherIndex = [];
        $subjectIndex = [];
        $roomIndex = [];
        $roomsByType = [];
        foreach ($p->rooms as $ri => $room) {
            $roomIndex[(string) $room->id] = $ri;
            $roomsByType[$room->type][] = $ri;
        }
        $this->roomPinned = array_fill(0, count($p->rooms), false);

        $this->reqLesson = $this->reqClass = $this->reqTeacher = $this->reqSubject = $this->reqRooms = [];
        $this->teacherLoad = [];
        $this->remaining = [];
        foreach ($p->lessons as $lesson) {
            if ($lesson->periodsPerWeek === 0) {
                continue;
            }

            $ci = $classIndex[(string) $lesson->classId];
            $ti = -1;
            if ($lesson->teacherId !== null) {
                $key = (string) $lesson->teacherId;
                $ti = $teacherIndex[$key] ??= count($teacherIndex);
                $this->teacherLoad[$ti] = ($this->teacherLoad[$ti] ?? 0) + $lesson->periodsPerWeek;
            }
            $sk = $subjectIndex[$ci.'|'.$lesson->subjectId] ??= count($subjectIndex);

            $rooms = null;
            if ($lesson->roomId !== null) {
                $ri = $roomIndex[(string) $lesson->roomId] ?? throw new InvalidProblem("Lesson {$lesson->id} asks for unknown room {$lesson->roomId}.");
                $rooms = [$ri];
                $this->roomPinned[$ri] = true;
            } elseif ($lesson->roomType !== null) {
                $rooms = $roomsByType[$lesson->roomType] ?? [];
            }

            $this->reqLesson[] = $lesson;
            $this->reqClass[] = $ci;
            $this->reqTeacher[] = $ti;
            $this->reqSubject[] = $sk;
            $this->reqRooms[] = $rooms;
            $this->remaining[] = $lesson->periodsPerWeek;
        }

        $this->teacherUnavail = array_fill(0, count($teacherIndex), []);
        foreach ($p->teacherUnavailable as $teacher => $slots) {
            $ti = $teacherIndex[(string) $teacher] ?? null;
            if ($ti === null) {
                continue; // teacher has no lessons in this run
            }
            foreach ($slots as $slot) {
                $this->teacherUnavail[$ti][$this->slotIndex($slot)] = true;
            }
        }

        $this->reqsByClass = array_fill(0, count($p->classes), []);
        $this->reqsByTeacher = array_fill(0, count($teacherIndex), []);
        foreach ($this->reqClass as $r => $ci) {
            $this->reqsByClass[$ci][] = $r;
            if ($this->reqTeacher[$r] >= 0) {
                $this->reqsByTeacher[$this->reqTeacher[$r]][] = $r;
            }
        }

        $this->subjectDayInit = array_fill(0, count($subjectIndex), array_fill(0, $this->dayCount, 0));
        $this->remainingInit = $this->remaining;
    }

    /** @var list<array<int,int>> */
    private array $subjectDayInit = [];

    /** @var list<int> */
    private array $remainingInit = [];

    private function resetState(): void
    {
        $p = $this->problem;
        $this->remaining = $this->remainingInit;
        $this->classBusy = array_fill(0, count($p->classes), []);
        $this->teacherBusy = array_fill(0, count($this->teacherUnavail), []);
        $this->roomBusy = [];
        foreach ($p->rooms as $ri => $room) {
            $this->roomBusy[$ri] = [];
            foreach ($room->unavailable as $slot) {
                $this->roomBusy[$ri][$this->slotIndex(Slot::from($slot))] = true;
            }
        }
        $this->subjectDay = $this->subjectDayInit;
        $this->excluded = array_fill(0, count($this->reqLesson), []);
        $this->assigned = [];

        $valid = count($this->validSlots);
        $this->classFree = array_fill(0, count($p->classes), $valid);
        $this->classRemaining = array_fill(0, count($p->classes), 0);
        $this->teacherRemaining = [];
        $this->teacherFree = [];
        foreach ($this->teacherUnavail as $ti => $unavail) {
            $free = 0;
            foreach ($this->validSlots as $s) {
                if (! isset($unavail[$s])) {
                    $free++;
                }
            }
            $this->teacherFree[$ti] = $free;
            $this->teacherRemaining[$ti] = 0;
        }
        foreach ($this->remaining as $r => $n) {
            $this->classRemaining[$this->reqClass[$r]] += $n;
            if ($this->reqTeacher[$r] >= 0) {
                $this->teacherRemaining[$this->reqTeacher[$r]] += $n;
            }
        }
    }

    private function reseed(int $attempt): void
    {
        $rng = new Randomizer(new Mt19937($this->options->seed + $attempt * 7919));
        $this->rng = $rng;
        $this->reqTie = [];
        foreach ($this->reqLesson as $r => $_) {
            $this->reqTie[$r] = $rng->getInt(0, 999_999) / 1_000_000;
        }
    }

    private Randomizer $rng;

    private function slotIndex(Slot $slot): int
    {
        $d = array_search($slot->day, $this->problem->days, true);

        return $d * $this->maxP + $slot->period - 1;
    }

    // ---------------------------------------------------------------------
    // Capacity pre-checks (cheap, give the clearest unsatisfiable reasons)
    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function precheck(): array
    {
        $reasons = [];
        $p = $this->problem;
        $valid = count($this->validSlots);
        $validSet = array_flip($this->validSlots);

        $classNeed = [];
        $subjectNeed = [];
        foreach ($this->reqLesson as $r => $lesson) {
            $classNeed[$this->reqClass[$r]] = ($classNeed[$this->reqClass[$r]] ?? 0) + $lesson->periodsPerWeek;
            $key = $lesson->classId.'|'.$lesson->subjectId;
            $subjectNeed[$key] = ($subjectNeed[$key] ?? 0) + $lesson->periodsPerWeek;
        }
        foreach ($classNeed as $ci => $need) {
            if ($need > $valid) {
                $reasons[] = sprintf('Class %s needs %d periods a week but only %d slots exist.', $p->classes[$ci], $need, $valid);
            }
        }

        // Teachers: available slots, also limited by the consecutive cap.
        $teacherIds = [];
        foreach ($this->reqLesson as $r => $lesson) {
            if ($this->reqTeacher[$r] >= 0) {
                $teacherIds[$this->reqTeacher[$r]] = $lesson->teacherId;
            }
        }
        foreach ($this->teacherLoad as $ti => $load) {
            $capacity = $this->teacherCapacity($ti, $validSet);
            if ($load > $capacity) {
                $reasons[] = sprintf(
                    'Teacher %s must teach %d periods a week but can teach at most %d (available slots%s).',
                    $teacherIds[$ti],
                    $load,
                    $capacity,
                    $p->maxConsecutive !== null ? ", max {$p->maxConsecutive} in a row" : '',
                );
            }
        }

        // Lesson-level: slots where teacher is available.
        foreach ($this->reqLesson as $r => $lesson) {
            $ti = $this->reqTeacher[$r];
            if ($ti < 0) {
                continue;
            }
            $free = 0;
            foreach ($this->validSlots as $s) {
                if (! isset($this->teacherUnavail[$ti][$s])) {
                    $free++;
                }
            }
            if ($lesson->periodsPerWeek > $free) {
                $reasons[] = sprintf('Lesson %s needs %d periods but its teacher is free in only %d slots.', $lesson->id, $lesson->periodsPerWeek, $free);
            }
        }

        // Rooms.
        $typeNeed = [];
        $roomNeed = [];
        foreach ($this->reqLesson as $r => $lesson) {
            if ($lesson->roomType !== null) {
                if ($this->reqRooms[$r] === []) {
                    $reasons[] = sprintf('Lesson %s needs a "%s" room but there is none.', $lesson->id, $lesson->roomType);
                }
                $typeNeed[$lesson->roomType] = ($typeNeed[$lesson->roomType] ?? 0) + $lesson->periodsPerWeek;
            } elseif ($lesson->roomId !== null) {
                $roomNeed[(string) $lesson->roomId] = ($roomNeed[(string) $lesson->roomId] ?? 0) + $lesson->periodsPerWeek;
            }
        }
        $roomCapacity = [];
        foreach ($p->rooms as $room) {
            $blocked = [];
            foreach ($room->unavailable as $slot) {
                $blocked[$this->slotIndex(Slot::from($slot))] = true;
            }
            $roomCapacity[(string) $room->id] = count(array_diff_key($validSet, $blocked));
        }
        foreach ($typeNeed as $type => $need) {
            $cap = 0;
            foreach ($p->rooms as $room) {
                if ($room->type === $type) {
                    $cap += $roomCapacity[(string) $room->id];
                }
            }
            if ($cap > 0 && $need > $cap) {
                $reasons[] = sprintf('"%s" rooms are needed for %d periods a week but offer only %d.', $type, $need, $cap);
            }
        }
        foreach ($roomNeed as $room => $need) {
            if ($need > $roomCapacity[$room]) {
                $reasons[] = sprintf('Room %s is needed for %d periods a week but is free in only %d slots.', $room, $need, $roomCapacity[$room]);
            }
        }

        // Hard per-day subject cap.
        if ($this->options->maxSameSubjectPerDay !== null) {
            $cap = $this->options->maxSameSubjectPerDay * $this->dayCount;
            foreach ($subjectNeed as $key => $need) {
                if ($need > $cap) {
                    [$class, $subject] = explode('|', $key, 2);
                    $reasons[] = sprintf('Class %s needs %d periods of subject %s, more than %d per day allows.', $class, $need, $subject, $this->options->maxSameSubjectPerDay);
                }
            }
        }

        return $reasons;
    }

    /** Max periods a teacher can teach: per day, each free run of length L allows L - floor(L/(m+1)). */
    private function teacherCapacity(int $ti, array $validSet): int
    {
        $m = $this->problem->maxConsecutive;
        $total = 0;
        foreach ($this->problem->days as $d => $day) {
            $run = 0;
            for ($period = 1; $period <= $this->problem->periodsPerDay[$day] + 1; $period++) {
                $s = $d * $this->maxP + $period - 1;
                $usable = $period <= $this->problem->periodsPerDay[$day]
                    && isset($validSet[$s]) && ! isset($this->teacherUnavail[$ti][$s]);
                if ($usable) {
                    $run++;

                    continue;
                }
                $total += $m === null ? $run : $run - intdiv($run, $m + 1);
                $run = 0;
            }
        }

        return $total;
    }

    // ---------------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------------

    /**
     * @param  array<int,array{0:int,1:int,2:int}>  $best
     * @return 'solved'|'exhausted'|'timeout'|'restart'
     */
    private function search(int $deadline, int $budget, int &$nodes, int &$backtracks, array &$best, int $totalUnits): string
    {
        /** @var list<array{r:int,cands:list<array{0:int,1:int}>,i:int,excl:list<int>,cur:?int}> $frames */
        $frames = [];
        $localBacktracks = 0;

        while (true) {
            if ((++$nodes & 127) === 0 && hrtime(true) > $deadline) {
                return 'timeout';
            }

            $r = $this->select();
            if ($r === self::DONE) {
                return 'solved';
            }

            if ($r !== self::DEAD) {
                $frames[] = ['r' => $r, 'cands' => $this->orderValues($r), 'i' => 0, 'excl' => [], 'cur' => null];
            } else {
                $backtracks++;
                if (++$localBacktracks > $budget) {
                    return 'restart';
                }
                if ($frames === []) {
                    return 'exhausted';
                }
                $this->rejectCurrent($frames[array_key_last($frames)]);
            }

            // Advance the top frame to its next candidate, popping exhausted frames.
            while (true) {
                $top = array_key_last($frames);
                $frame = &$frames[$top];
                $placed = false;
                while ($frame['i'] < count($frame['cands'])) {
                    [$s, $room] = $frame['cands'][$frame['i']++];
                    if ($this->legal($frame['r'], $s) && ($room < 0 || ! isset($this->roomBusy[$room][$s]))) {
                        $frame['cur'] = $this->assign($frame['r'], $s, $room);
                        $placed = true;
                        break;
                    }
                }
                if ($placed) {
                    unset($frame);
                    break;
                }

                foreach ($frame['excl'] as $s) {
                    if (--$this->excluded[$frame['r']][$s] === 0) {
                        unset($this->excluded[$frame['r']][$s]);
                    }
                }
                unset($frame);
                array_pop($frames);
                if ($frames === []) {
                    return 'exhausted';
                }
                $this->rejectCurrent($frames[array_key_last($frames)]);
            }

            if (count($this->assigned) > count($best)) {
                $best = $this->assigned;
            }
        }
    }

    /** Undo a frame's current assignment and exclude that slot for the lesson's remaining periods. */
    private function rejectCurrent(array &$frame): void
    {
        $id = $frame['cur'];
        [, $s] = $this->assigned[$id];
        $this->unassign($id);
        $frame['cur'] = null;

        // A failed slot only rules out siblings in the same slot when no other
        // room was tried there; with several candidate rooms per slot, exclude
        // the slot only once all of its rooms have been tried.
        $next = $frame['cands'][$frame['i']] ?? null;
        if ($next === null || $next[0] !== $s) {
            $this->excluded[$frame['r']][$s] = ($this->excluded[$frame['r']][$s] ?? 0) + 1;
            $frame['excl'][] = $s;
        }
    }

    /** Pick the next lesson (MRV) or report DONE / DEAD. */
    private function select(): int
    {
        foreach ($this->classRemaining as $ci => $need) {
            if ($need > $this->classFree[$ci]) {
                return self::DEAD;
            }
        }
        foreach ($this->teacherRemaining as $ti => $need) {
            if ($need > $this->teacherFree[$ti]) {
                return self::DEAD;
            }
        }

        $best = self::DONE;
        $bestSlack = PHP_INT_MAX;
        $bestWeight = -1;
        $bestLoad = -1;
        $bestTie = 2.0;

        foreach ($this->remaining as $r => $need) {
            if ($need === 0) {
                continue;
            }
            $limit = $bestSlack + $need; // count no further than can still win
            $domain = 0;
            foreach ($this->validSlots as $s) {
                if ($this->legal($r, $s) && $this->roomFor($r, $s) !== null) {
                    if (++$domain > $limit) {
                        break;
                    }
                }
            }
            $slack = $domain - $need;
            if ($slack < 0) {
                $this->wipeouts[$r]++;

                return self::DEAD;
            }
            $ti = $this->reqTeacher[$r];
            $load = $ti >= 0 ? $this->teacherLoad[$ti] : 0;
            $weight = $this->wipeouts[$r];
            if ([$slack, -$weight, -$load, $this->reqTie[$r]] < [$bestSlack, -$bestWeight, -$bestLoad, $bestTie]) {
                $best = $r;
                $bestSlack = $slack;
                $bestWeight = $weight;
                $bestLoad = $load;
                $bestTie = $this->reqTie[$r];
            }
        }

        return $best;
    }

    private function legal(int $r, int $s): bool
    {
        if (isset($this->excluded[$r][$s]) || isset($this->classBusy[$this->reqClass[$r]][$s])) {
            return false;
        }

        $cap = $this->options->maxSameSubjectPerDay;
        if ($cap !== null && $cap <= $this->subjectDay[$this->reqSubject[$r]][$this->slotDay[$s]]) {
            return false;
        }

        $ti = $this->reqTeacher[$r];
        if ($ti < 0) {
            return true;
        }
        if (isset($this->teacherBusy[$ti][$s]) || isset($this->teacherUnavail[$ti][$s])) {
            return false;
        }

        $m = $this->problem->maxConsecutive;
        if ($m !== null) {
            $busy = $this->teacherBusy[$ti];
            $dayStart = $this->slotDay[$s] * $this->maxP;
            $run = 1;
            for ($x = $s - 1; $x >= $dayStart && isset($busy[$x]); $x--) {
                $run++;
            }
            for ($x = $s + 1; $x < $dayStart + $this->maxP && isset($busy[$x]); $x++) {
                $run++;
            }
            if ($run > $m) {
                return false;
            }
        }

        return true;
    }

    /** First free candidate room (-1 = lesson needs no room, null = none free). */
    private function roomFor(int $r, int $s): ?int
    {
        $rooms = $this->reqRooms[$r];
        if ($rooms === null) {
            return -1;
        }
        foreach ($rooms as $ri) {
            if (! isset($this->roomBusy[$ri][$s])) {
                return $ri;
            }
        }

        return null;
    }

    /**
     * Legal values for lesson $r, best first: soft cost (same subject already
     * that day, subject on a neighbouring day) then a seeded random key.
     * Typed-room lessons get one candidate per pinned room plus one for the
     * interchangeable unpinned rooms, so room choice never loses a solution.
     *
     * @return list<array{0:int,1:int}>
     */
    private function orderValues(int $r): array
    {
        $sk = $this->reqSubject[$r];
        $perDay = $this->subjectDay[$sk];

        // Least-constraining value: how much do the class's and the teacher's
        // other unfinished lessons need each slot (weighted by their scarcity)?
        $pressure = [];
        $ti = $this->reqTeacher[$r];
        $neighbours = $this->reqsByClass[$this->reqClass[$r]];
        if ($ti >= 0) {
            $neighbours = array_merge($neighbours, $this->reqsByTeacher[$ti]);
        }
        foreach ($neighbours as $n) {
            if ($n === $r || $this->remaining[$n] === 0 || isset($pressure[$n])) {
                continue;
            }
            $domain = 0;
            foreach ($this->validSlots as $s) {
                if ($this->legal($n, $s)) {
                    $domain++;
                }
            }
            $pressure[$n] = $this->remaining[$n] / max(1, $domain);
        }

        $keyed = [];
        foreach ($this->validSlots as $s) {
            if (! $this->legal($r, $s)) {
                continue;
            }
            $d = $this->slotDay[$s];
            $contention = 0.0;
            foreach ($pressure as $n => $w) {
                if ($this->legal($n, $s)) {
                    $contention += $w;
                }
            }
            $cost = self::W_SAME_DAY * $perDay[$d]
                + self::W_ADJACENT_DAY * ((($perDay[$d - 1] ?? 0) > 0 ? 1 : 0) + (($perDay[$d + 1] ?? 0) > 0 ? 1 : 0))
                + self::W_CONTENTION * $contention;
            $key = $cost + $this->rng->getInt(0, 999_999) / 1_000_000;

            foreach ($this->roomCandidates($r, $s) as $room) {
                $keyed[] = [$key, $s, $room];
            }
        }
        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn ($k) => [$k[1], $k[2]], $keyed);
    }

    /** @return list<int> */
    private function roomCandidates(int $r, int $s): array
    {
        $rooms = $this->reqRooms[$r];
        if ($rooms === null) {
            return [-1];
        }
        $out = [];
        $unpinnedTaken = false;
        foreach ($rooms as $ri) {
            if (isset($this->roomBusy[$ri][$s])) {
                continue;
            }
            if ($this->roomPinned[$ri]) {
                $out[] = $ri;
            } elseif (! $unpinnedTaken) {
                // Prefer an unpinned room first: it cannot block a fixed-room lesson.
                array_unshift($out, $ri);
                $unpinnedTaken = true;
            }
        }

        return $out;
    }

    private function assign(int $r, int $s, int $room): int
    {
        $ci = $this->reqClass[$r];
        $ti = $this->reqTeacher[$r];
        $this->classBusy[$ci][$s] = true;
        $this->classFree[$ci]--;
        $this->classRemaining[$ci]--;
        if ($ti >= 0) {
            $this->teacherBusy[$ti][$s] = true;
            $this->teacherFree[$ti]--;
            $this->teacherRemaining[$ti]--;
        }
        if ($room >= 0) {
            $this->roomBusy[$room][$s] = true;
        }
        $this->subjectDay[$this->reqSubject[$r]][$this->slotDay[$s]]++;
        $this->remaining[$r]--;

        $id = $this->nextAssignId++;
        $this->assigned[$id] = [$r, $s, $room];

        return $id;
    }

    private function unassign(int $id): void
    {
        [$r, $s, $room] = $this->assigned[$id];
        unset($this->assigned[$id]);
        $ci = $this->reqClass[$r];
        $ti = $this->reqTeacher[$r];
        unset($this->classBusy[$ci][$s]);
        $this->classFree[$ci]++;
        $this->classRemaining[$ci]++;
        if ($ti >= 0) {
            unset($this->teacherBusy[$ti][$s]);
            $this->teacherFree[$ti]++;
            $this->teacherRemaining[$ti]++;
        }
        if ($room >= 0) {
            unset($this->roomBusy[$room][$s]);
        }
        $this->subjectDay[$this->reqSubject[$r]][$this->slotDay[$s]]--;
        $this->remaining[$r]++;
    }

    // ---------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------

    /**
     * @param  array<int,array{0:int,1:int,2:int}>  $assigned
     * @return list<Placement>
     */
    private function placements(array $assigned): array
    {
        $out = [];
        foreach ($assigned as [$r, $s, $room]) {
            $d = intdiv($s, $this->maxP);
            $out[] = new Placement(
                $this->reqLesson[$r],
                $this->problem->days[$d],
                $s % $this->maxP + 1,
                $room >= 0 ? $this->problem->rooms[$room]->id : null,
            );
        }
        $days = array_flip(array_map('strval', $this->problem->days));
        usort($out, function (Placement $a, Placement $b) use ($days): int {
            return [(string) $a->lesson->classId, $days[(string) $a->day], $a->period]
                <=> [(string) $b->lesson->classId, $days[(string) $b->day], $b->period];
        });

        return $out;
    }

    /**
     * @param  list<Placement>  $placements
     * @return array<string,int>
     */
    private function unplaced(array $placements): array
    {
        $placed = [];
        foreach ($placements as $pl) {
            $placed[$pl->lesson->id] = ($placed[$pl->lesson->id] ?? 0) + 1;
        }
        $out = [];
        foreach ($this->reqLesson as $lesson) {
            $missing = $lesson->periodsPerWeek - ($placed[$lesson->id] ?? 0);
            if ($missing > 0) {
                $out[$lesson->id] = $missing;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function exhaustedReasons(): array
    {
        $reasons = ['Every arrangement was searched: no timetable satisfies all hard constraints (teacher/class/room clashes, availability, consecutive limit).'];
        $hot = array_filter($this->wipeouts);
        arsort($hot);
        foreach (array_slice($hot, 0, 5, true) as $r => $count) {
            $l = $this->reqLesson[$r];
            $reasons[] = sprintf(
                'Hardest to place: lesson %s (class %s, subject %s, teacher %s) ran out of slots %d time(s).',
                $l->id, $l->classId, $l->subjectId, $l->teacherId ?? 'none', $count,
            );
        }

        return $reasons;
    }

    /**
     * @param  list<Placement>  $placements
     * @return array<string,int|float>
     */
    private function stats(int $started, int $nodes, int $backtracks, int $attempt, array $placements): array
    {
        $perDay = [];
        foreach ($placements as $pl) {
            $key = $pl->lesson->classId.'|'.$pl->lesson->subjectId.'|'.$pl->day;
            $perDay[$key] = ($perDay[$key] ?? 0) + 1;
        }
        $repeats = 0;
        foreach ($perDay as $n) {
            $repeats += max(0, $n - 1);
        }

        return [
            'elapsed_ms' => $this->ms($started),
            'nodes' => $nodes,
            'backtracks' => $backtracks,
            'restarts' => $attempt,
            'same_subject_repeats' => $repeats,
        ];
    }

    private function ms(int $started): float
    {
        return round((hrtime(true) - $started) / 1e6, 1);
    }
}
