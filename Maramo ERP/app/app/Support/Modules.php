<?php

namespace App\Support;

use Filament\Contracts\Plugin;

/**
 * What the demo app wires for each Classivo package, when that package is installed.
 *
 * app/composer.json is the only list of installed packages. Everything here is
 * looked up by class name and skipped when the class does not exist, so the app
 * boots, seeds and passes its tests with any subset of packages: add one with
 * `composer require classivo/<name>` and nothing else.
 */
final class Modules
{
    /** Admin panel plugins, in menu order. "Class" means Class::make(); "Class::method" calls that factory. */
    public const ADMIN_PLUGINS = [
        'Classivo\Core\CorePlugin',
        'Classivo\Students\StudentsPlugin',
        'Classivo\Fees\FeesPlugin',
        'Classivo\ReportCards\ReportCardsPlugin',
        'Classivo\Hr\HrPlugin',
    ];

    /** Student portal (/student) plugins. */
    public const STUDENT_PLUGINS = [
        'Classivo\OnlineExams\OnlineExamsStudentPlugin',
        'Classivo\Fees\FeesPortalPlugin::student',
        'Classivo\Communication\StudentPlugin',
        'Classivo\Documents\DocumentsPortalPlugin',
    ];

    /** Parent portal (/parent) plugins. */
    public const GUARDIAN_PLUGINS = [
        'Classivo\OnlineExams\OnlineExamsGuardianPlugin',
        'Classivo\Fees\FeesPortalPlugin::guardian',
        'Classivo\Communication\GuardianPlugin',
        'Classivo\Documents\DocumentsPortalPlugin',
        'Classivo\Transport\TransportParentPlugin',
    ];

    /** Demo data seeders, run after DemoSchoolSeeder (core + students). */
    public const DEMO_SEEDERS = [
        'Classivo\Exams\Database\Seeders\ExamsDefaultsSeeder',
        'Classivo\Transport\Database\Seeders\TransportDemoSeeder',
        'Classivo\Hr\Database\Seeders\HrSeeder',
        'Classivo\Library\Database\Seeders\LibrarySeeder',
        'Classivo\OnlineExams\Database\Seeders\OnlineExamsDefaultsSeeder',
        'Classivo\FrontOffice\Database\Seeders\FrontOfficeSeeder',
        'Classivo\Hostel\Database\Seeders\HostelSeeder',
        'Classivo\Lms\Database\Seeders\LmsSeeder',
        'Classivo\SkillPrograms\Database\Seeders\SkillProgramsSeeder',
        'Classivo\Inventory\Database\Seeders\InventorySeeder',
        'Classivo\Cms\Database\Seeders\CmsSeeder',
        'Classivo\Udise\Database\Seeders\UdiseSeeder',
        'Classivo\StudentLife\Database\Seeders\StudentLifeSeeder',
        'Classivo\Finance\Database\Seeders\ChartOfAccountsSeeder',
    ];

    /** Packages that publish their permission list with a static all(). */
    public const PERMISSION_SOURCES = [
        'Classivo\Core\Support\Permissions',
        'Classivo\Students\Support\StudentsPermissions',
        'Classivo\Exams\Support\ExamsPermissions',
        'Classivo\ReportCards\Support\Permissions',
        'Classivo\Transport\Support\TransportPermissions',
        'Classivo\Hr\Support\HrPermissions',
        'Classivo\Library\Support\LibraryPermissions',
        'Classivo\FrontOffice\Support\FrontOfficePermissions',
        'Classivo\Hostel\Support\HostelPermissions',
        'Classivo\Lms\Support\LmsPermissions',
        'Classivo\SkillPrograms\Support\SkillProgramsPermissions',
        'Classivo\Inventory\Support\InventoryPermissions',
        'Classivo\Cms\Support\CmsPermissions',
        'Classivo\Udise\Support\UdisePermissions',
        'Classivo\StudentLife\Support\StudentLifePermissions',
        'Classivo\Security\Support\SecurityPermissions',
        'Classivo\AcademicsExtras\Support\AcademicsPermissions',
        'Classivo\Platform\Support\PlatformPermissions',
        'Classivo\Fees\Support\FeesPermissions',
        'Classivo\Finance\Support\FinancePermissions',
        'Classivo\Communication\Support\CommunicationPermissions',
    ];

    /** Permissions of packages without an all() list, keyed by a class that proves the package is installed. */
    public const PERMISSIONS = [
        'Classivo\Exams\ExamsPlugin' => ['exam.enter_marks', 'exam.publish_result'],
        'Classivo\Timetable\TimetablePlugin' => ['timetable.view', 'timetable.edit', 'timetable.generate', 'timetable.substitute'],
        'Classivo\OnlineExams\OnlineExamsPlugin' => [
            'online_exam.manage_questions', 'online_exam.manage_exams', 'online_exam.view_results',
            'online_exam.grade', 'online_exam.publish_results', 'online_exam.take', 'online_exam.view_child_results',
        ],
        'Classivo\Payments\PaymentsPlugin' => ['payments.view', 'payments.refund', 'payments.credentials'],
        'Classivo\Documents\DocumentsPlugin' => ['documents.view', 'documents.manage'],
        'Classivo\Reports\ReportsPlugin' => ['reports.view'],
    ];

    /** Scheduled commands, keyed by a class that proves the package is installed: [command, frequency method]. */
    public const SCHEDULES = [
        'Classivo\Fees\FeesPlugin' => [['fees:accrue-fines', 'dailyAt', '01:00'], ['fees:send-reminders', 'dailyAt', '04:00']],
        'Classivo\Payments\PaymentsPlugin' => [['payments:expire-pending', 'everyFifteenMinutes']],
        'Classivo\Transport\TransportParentPlugin' => [['transport:prune-positions', 'daily']],
    ];

    public static function installed(string $class): bool
    {
        return class_exists($class) || trait_exists($class) || interface_exists($class);
    }

    /**
     * Plugin instances for the installed packages in a list.
     *
     * @param  list<string>  $list
     * @return list<Plugin>
     */
    public static function plugins(array $list): array
    {
        $plugins = [];

        foreach ($list as $entry) {
            [$class, $method] = array_pad(explode('::', $entry, 2), 2, 'make');

            if (class_exists($class)) {
                $plugins[] = $class::$method();
            }
        }

        return $plugins;
    }

    /**
     * @param  list<string>  $list
     * @return list<string>
     */
    public static function existing(array $list): array
    {
        return array_values(array_filter($list, fn (string $class): bool => class_exists($class)));
    }

    /** @return list<string> every permission of the installed packages */
    public static function permissions(): array
    {
        $names = [];

        foreach (self::PERMISSIONS as $marker => $list) {
            if (class_exists($marker)) {
                $names = array_merge($names, $list);
            }
        }

        foreach (self::existing(self::PERMISSION_SOURCES) as $source) {
            $names = array_merge($names, $source::all());
        }

        // One `reports.<key>` permission per built-in table report (classivo/reports).
        foreach ((array) config('classivo-reports.tabular', []) as $class) {
            if (is_string($class) && class_exists($class)) {
                $names[] = 'reports.'.app($class)->key();
            }
        }

        return array_values(array_unique($names));
    }
}
