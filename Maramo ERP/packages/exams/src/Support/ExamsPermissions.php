<?php

namespace Classivo\Exams\Support;

use Classivo\Core\Support\Permissions;

/**
 * Permission names for the exam screens. Plain Laravel ability strings, so they
 * work with spatie/laravel-permission (see ExamsPermissionSeeder) or your own
 * Gate. Deny by default: a user without the permission cannot see or use the
 * screen. The marks-entry and publish abilities stay `exam.enter_marks` and
 * `exam.publish_result` (config `classivo-exams.abilities`).
 */
final class ExamsPermissions
{
    public const RESOURCES = [
        'exams' => 'exams.exams',
        'groups' => 'exams.groups',
        'grades' => 'exams.grades',
        'marks_distributions' => 'exams.marks_distributions',
        'attendances' => 'exams.attendances',
    ];

    /** Build / edit an exam's subject schedule. */
    public const SCHEDULE_BUILD = 'exams.schedule.build';

    /** Open the marks register read-only (entering marks needs exam.enter_marks). */
    public const MARKS_VIEW = 'exams.marks.view';

    /** Open the results page (publishing needs exam.publish_result). */
    public const RESULTS_VIEW = 'exams.results.view';

    /** Generate / regenerate results for a class-section. */
    public const RESULTS_GENERATE = 'exams.results.generate';

    /** @return list<string> */
    public static function all(): array
    {
        return Permissions::build(self::RESOURCES, [
            self::SCHEDULE_BUILD, self::MARKS_VIEW, self::RESULTS_VIEW, self::RESULTS_GENERATE, MarksScope::ANY_CLASS,
            'exam.enter_marks', 'exam.publish_result',
        ]);
    }

    public static function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }
}
