<?php

namespace Classivo\Timetable\Exceptions;

use RuntimeException;

/**
 * Thrown when a timetable period would violate a scheduling invariant: a
 * teacher double-booked across class-sections in the same time slot, a
 * class-section with overlapping periods, or an end time not after the start
 * time. Carries the human-readable conflict messages so callers (the Class
 * Timetable page) can surface them in a notification.
 */
class TimetableConflictException extends RuntimeException
{
    /** @param list<string> $messages */
    public function __construct(public array $messages)
    {
        parent::__construct($messages[0] ?? 'Timetable conflict.');
    }

    /** @param list<string> $messages */
    public static function make(array $messages): self
    {
        return new self($messages);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}
