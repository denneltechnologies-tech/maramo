<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/**
 * One teaching requirement: class X studies subject Y with teacher Z for N
 * periods a week. A lesson can optionally need a room — either one specific
 * room ($roomId) or any room of a type ($roomType, e.g. "lab").
 *
 * The id is free-form and comes back on every {@see Placement}; when omitted it
 * defaults to "class:subject:teacher".
 */
final class Lesson
{
    public readonly string $id;

    public function __construct(
        public readonly int|string $classId,
        public readonly int|string $subjectId,
        public readonly int|string|null $teacherId,
        public readonly int $periodsPerWeek,
        public readonly ?string $roomType = null,
        public readonly int|string|null $roomId = null,
        ?string $id = null,
    ) {
        if ($periodsPerWeek < 0) {
            throw new InvalidProblem('periodsPerWeek cannot be negative.');
        }

        if ($roomType !== null && $roomId !== null) {
            throw new InvalidProblem('A lesson takes either a roomType or a roomId, not both.');
        }

        $this->id = $id ?? "{$classId}:{$subjectId}:".($teacherId ?? '-');
    }

    public function needsRoom(): bool
    {
        return $this->roomType !== null || $this->roomId !== null;
    }
}
