<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/** One scheduled period of a lesson in the solution. */
final class Placement
{
    public function __construct(
        public readonly Lesson $lesson,
        public readonly int|string $day,
        public readonly int $period,
        public readonly int|string|null $roomId = null,
    ) {}

    /** @return array{lesson:string,class:int|string,subject:int|string,teacher:int|string|null,day:int|string,period:int,room:int|string|null} */
    public function toArray(): array
    {
        return [
            'lesson' => $this->lesson->id,
            'class' => $this->lesson->classId,
            'subject' => $this->lesson->subjectId,
            'teacher' => $this->lesson->teacherId,
            'day' => $this->day,
            'period' => $this->period,
            'room' => $this->roomId,
        ];
    }
}
