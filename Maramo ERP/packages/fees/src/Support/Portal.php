<?php

namespace Classivo\Fees\Support;

use Classivo\Students\Models\Guardian;
use Classivo\Students\Models\Student;
use Illuminate\Support\Collection;

/**
 * Which students a signed-in student or guardian may see fee data for: the
 * student whose `user_id` is the user, and every student linked to a guardian
 * whose `user_id` is the user.
 */
final class Portal
{
    /** @return Collection<int, Student> */
    public static function studentsFor(mixed $user): Collection
    {
        if ($user === null || ! class_exists(Student::class)) {
            return collect();
        }

        $userId = (int) $user->getAuthIdentifier();

        $own = Student::query()->withoutGlobalScopes()->where('user_id', $userId)->get();

        $wards = class_exists(Guardian::class)
            ? Guardian::query()->withoutGlobalScopes()->where('user_id', $userId)->get()
                ->flatMap(fn (Guardian $g) => $g->students()->withoutGlobalScopes()->get())
            : collect();

        return $own->concat($wards)->unique('id')->values();
    }

    public static function canSeeStudent(mixed $user, int $studentId): bool
    {
        return self::studentsFor($user)->contains('id', $studentId);
    }
}
