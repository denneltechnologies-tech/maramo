<?php

declare(strict_types=1);

namespace Classivo\Timetable\Generator;

/** A bookable room. Lessons ask for a room by id or by type. */
final class Room
{
    /** @param list<Slot|array{0:int|string,1:int}> $unavailable */
    public function __construct(
        public readonly int|string $id,
        public readonly string $type = 'classroom',
        public readonly array $unavailable = [],
    ) {}
}
