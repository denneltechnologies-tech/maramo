<?php

namespace Classivo\Fees\Support;

use Classivo\Fees\Contracts\ReminderRecipients;
use Classivo\Students\Models\Student;
use Throwable;

/**
 * Default reminder audience: every guardian of the student with a valid e-mail
 * (when the students package exposes a `guardians` relation), else the
 * student's own e-mail. Deduplicated, case-insensitively.
 */
class GuardianEmailRecipients implements ReminderRecipients
{
    public function for(Student $student): array
    {
        $out = [];

        if (method_exists($student, 'guardians')) {
            try {
                foreach ($student->guardians()->get() as $guardian) {
                    $this->push($out, $guardian->email ?? null, $guardian->name ?? null);
                }
            } catch (Throwable) {
                // guardians table not installed — fall through
            }
        }

        if ($out === []) {
            $name = trim((string) ($student->full_name ?? trim(($student->first_name ?? '').' '.($student->last_name ?? ''))));
            $this->push($out, $student->email ?? null, $name !== '' ? $name : null);
        }

        return array_values($out);
    }

    /** @param  array<string, array{email: string, name: ?string}>  $out */
    private function push(array &$out, mixed $email, mixed $name): void
    {
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $out[strtolower($email)] ??= ['email' => $email, 'name' => is_string($name) && $name !== '' ? $name : null];
    }
}
