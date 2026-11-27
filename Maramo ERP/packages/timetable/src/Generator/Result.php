<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/**
 * Outcome of a run.
 *
 * - SOLVED: every lesson period is placed; $placements is the timetable.
 * - UNSATISFIABLE: proven impossible. $reasons says why in plain words — either
 *   a capacity check that fails before searching, or an exhausted search.
 * - TIMEOUT: the time limit ran out first. $placements holds the best partial
 *   timetable and $unplaced lists what is missing; it is NOT a proof.
 */
final class Result
{
    public const SOLVED = 'solved';

    public const UNSATISFIABLE = 'unsatisfiable';

    public const TIMEOUT = 'timeout';

    /**
     * @param  list<Placement>  $placements
     * @param  list<string>  $reasons
     * @param  array<string,int>  $unplaced  lesson id => periods not placed
     * @param  array<string,int|float>  $stats
     */
    public function __construct(
        public readonly string $status,
        public readonly array $placements = [],
        public readonly array $reasons = [],
        public readonly array $unplaced = [],
        public readonly array $stats = [],
    ) {}

    public function isSolved(): bool
    {
        return $this->status === self::SOLVED;
    }

    /** @return list<Placement> */
    public function forClass(int|string $classId): array
    {
        return array_values(array_filter($this->placements, fn (Placement $p) => $p->lesson->classId === $classId));
    }

    /** @return list<Placement> */
    public function forTeacher(int|string $teacherId): array
    {
        return array_values(array_filter($this->placements, fn (Placement $p) => $p->lesson->teacherId === $teacherId));
    }

    /** Human-readable summary for logs / notifications. */
    public function report(): string
    {
        $lines = [strtoupper($this->status)];
        foreach ($this->reasons as $reason) {
            $lines[] = "- {$reason}";
        }
        foreach ($this->unplaced as $lesson => $count) {
            $lines[] = "- lesson {$lesson}: {$count} period(s) not placed";
        }

        return implode("\n", $lines);
    }
}
