<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/**
 * The full input of a timetable generation run.
 *
 * - days: the weekday keys in order (e.g. [1,2,3,4,5,6] for Mon..Sat).
 * - periodsPerDay: one number for every day, or a map day => count
 *   (e.g. a shorter Saturday).
 * - classes: every class (class-section) id to schedule.
 * - lessons: the teaching requirements ({@see Lesson}).
 * - teacherUnavailable: teacherId => list of slots the teacher cannot teach.
 * - rooms: optional bookable rooms; only lessons that ask for a room use them.
 * - maxConsecutive: HARD cap on back-to-back teaching periods for a teacher
 *   (null = no cap).
 * - blockedSlots: slots nobody is scheduled in (assembly, games period ...).
 */
final class Problem
{
    /** @var list<int|string> */
    public readonly array $days;

    /** @var array<int|string,int> day => number of periods */
    public readonly array $periodsPerDay;

    /** @var list<int|string> */
    public readonly array $classes;

    /** @var list<Lesson> */
    public readonly array $lessons;

    /** @var array<int|string,list<Slot>> */
    public readonly array $teacherUnavailable;

    /** @var list<Room> */
    public readonly array $rooms;

    /** @var list<Slot> */
    public readonly array $blockedSlots;

    /**
     * @param  list<int|string>  $days
     * @param  int|array<int|string,int>  $periodsPerDay
     * @param  list<int|string>  $classes
     * @param  list<Lesson>  $lessons
     * @param  array<int|string,list<Slot|array{0:int|string,1:int}>>  $teacherUnavailable
     * @param  list<Room>  $rooms
     * @param  list<Slot|array{0:int|string,1:int}>  $blockedSlots
     */
    public function __construct(
        array $days,
        int|array $periodsPerDay,
        array $classes,
        array $lessons,
        array $teacherUnavailable = [],
        array $rooms = [],
        public readonly ?int $maxConsecutive = null,
        array $blockedSlots = [],
    ) {
        if ($days === []) {
            throw new InvalidProblem('At least one day is required.');
        }
        if (count(array_unique($days)) !== count($days)) {
            throw new InvalidProblem('Days must be unique.');
        }
        $this->days = array_values($days);

        $perDay = [];
        foreach ($this->days as $day) {
            $count = is_int($periodsPerDay) ? $periodsPerDay : ($periodsPerDay[$day] ?? null);
            if ($count === null || $count < 0) {
                throw new InvalidProblem("No valid period count for day {$day}.");
            }
            $perDay[$day] = (int) $count;
        }
        $this->periodsPerDay = $perDay;

        if (count(array_unique($classes)) !== count($classes)) {
            throw new InvalidProblem('Class ids must be unique.');
        }
        $this->classes = array_values($classes);

        $ids = [];
        foreach ($lessons as $lesson) {
            if (! $lesson instanceof Lesson) {
                throw new InvalidProblem('lessons must contain Lesson objects.');
            }
            if (isset($ids[$lesson->id])) {
                throw new InvalidProblem("Duplicate lesson id {$lesson->id}.");
            }
            $ids[$lesson->id] = true;
            if (! in_array($lesson->classId, $this->classes, true)) {
                throw new InvalidProblem("Lesson {$lesson->id} refers to unknown class {$lesson->classId}.");
            }
        }
        $this->lessons = array_values($lessons);

        $unavailable = [];
        foreach ($teacherUnavailable as $teacher => $slots) {
            $unavailable[$teacher] = array_map(fn ($s) => $this->checkSlot(Slot::from($s)), array_values($slots));
        }
        $this->teacherUnavailable = $unavailable;

        $roomIds = [];
        foreach ($rooms as $room) {
            if (! $room instanceof Room) {
                throw new InvalidProblem('rooms must contain Room objects.');
            }
            if (isset($roomIds[$room->id])) {
                throw new InvalidProblem("Duplicate room id {$room->id}.");
            }
            $roomIds[$room->id] = true;
            foreach ($room->unavailable as $s) {
                $this->checkSlot(Slot::from($s));
            }
        }
        $this->rooms = array_values($rooms);

        if ($maxConsecutive !== null && $maxConsecutive < 1) {
            throw new InvalidProblem('maxConsecutive must be at least 1.');
        }

        $this->blockedSlots = array_map(fn ($s) => $this->checkSlot(Slot::from($s)), array_values($blockedSlots));
    }

    public function maxPeriods(): int
    {
        return max($this->periodsPerDay) ?: 0;
    }

    private function checkSlot(Slot $slot): Slot
    {
        if (! array_key_exists($slot->day, $this->periodsPerDay)) {
            throw new InvalidProblem("Unknown day in {$slot}.");
        }
        if ($slot->period > $this->periodsPerDay[$slot->day]) {
            throw new InvalidProblem("{$slot} is beyond that day's last period.");
        }

        return $slot;
    }
}
