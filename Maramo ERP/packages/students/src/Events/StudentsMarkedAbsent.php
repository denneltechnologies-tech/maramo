<?php

namespace Classivo\Students\Events;

use Classivo\Core\Models\ClassSection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when daily attendance marks students absent. A notification module
 * (SMS / WhatsApp / email) can listen and alert guardians.
 */
class StudentsMarkedAbsent
{
    use Dispatchable;

    /** @param  array<int, int>  $studentIds */
    public function __construct(public ClassSection $classSection, public string $date, public array $studentIds) {}
}
