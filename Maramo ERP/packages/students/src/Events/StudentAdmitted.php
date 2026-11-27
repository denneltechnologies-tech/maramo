<?php

namespace Classivo\Students\Events;

use Classivo\Students\Models\Student;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a student is admitted (outside the DB transaction).
 * Listen to it to send a welcome message, assign fees, create a login, etc.
 */
class StudentAdmitted
{
    use Dispatchable;

    /** @param  array<string, mixed>  $data  the raw admission payload */
    public function __construct(public Student $student, public array $data = []) {}
}
