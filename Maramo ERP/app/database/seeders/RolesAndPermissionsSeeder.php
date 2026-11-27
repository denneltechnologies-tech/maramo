<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Modules;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions checked by the installed Classivo packages (deny by default), an
 * Admin role holding all of them, a read-only Viewer role, a Teacher role holding the classroom ones and
 * Student / Parent roles for the portals. Only Admin users may open the admin
 * panel in the demo. Role permissions of packages that are not installed are
 * skipped.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** @var array<int, string> */
    public const TEACHER_PERMISSIONS = [
        'exam.enter_marks', 'timetable.view',
        'online_exam.manage_questions', 'online_exam.manage_exams', 'online_exam.view_results', 'online_exam.grade',
    ];

    /** @var array<int, string> */
    public const STUDENT_PERMISSIONS = ['online_exam.take', 'communication.panel.student'];

    /** @var array<int, string> */
    public const PARENT_PERMISSIONS = ['online_exam.view_child_results', 'communication.panel.guardian', 'transport.where_is_my_bus'];

    /**
     * Read-only "Viewer" (public demo login): every list/view screen, dashboards, the
     * Reports Centre and printable/downloadable documents, but no create, edit, delete
     * or workflow action.
     */
    public static function viewerPermissions(array $all): array
    {
        return array_values(array_filter($all, fn (string $p): bool => str_starts_with($p, 'reports.')
            || (bool) preg_match('/\.(view|view_results|dashboard|stats|print|download|export)$/', $p)));
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = Modules::permissions();

        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $installed = fn (array $names): array => array_values(array_intersect($names, $all));

        Role::findOrCreate('Admin', 'web')->syncPermissions($all);
        Role::findOrCreate('Teacher', 'web')->syncPermissions($installed(self::TEACHER_PERMISSIONS));
        Role::findOrCreate('Student', 'web')->syncPermissions($installed(self::STUDENT_PERMISSIONS));
        Role::findOrCreate('Parent', 'web')->syncPermissions($installed(self::PARENT_PERMISSIONS));
        Role::findOrCreate('Viewer', 'web')->syncPermissions(self::viewerPermissions($all));

        User::where('email', 'admin@example.com')->first()?->assignRole('Admin');
        User::where('email', 'teacher@example.com')->first()?->assignRole('Teacher');
        User::where('email', 'student@example.com')->first()?->assignRole('Student');
        User::where('email', 'parent@example.com')->first()?->assignRole('Parent');
        User::where('email', 'viewer@example.com')->first()?->assignRole('Viewer');
    }
}
