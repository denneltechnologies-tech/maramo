<?php

namespace Classivo\Fees\Contracts;

use Classivo\Fees\Support\GuardianEmailRecipients;
use Classivo\Students\Models\Student;

/**
 * Who receives a fee reminder for a student. The default binding
 * ({@see GuardianEmailRecipients}) returns guardian
 * e-mail addresses, falling back to the student's own e-mail. A host app can
 * bind its own (for example to address User models with their own channels).
 */
interface ReminderRecipients
{
    /**
     * @return list<array{email: string, name: ?string}>
     */
    public function for(Student $student): array;
}
