<?php

namespace Classivo\Hr\Tests;

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Hr\HrServiceProvider;
use Classivo\Hr\Support\HrPermissions;
use Classivo\Hr\Tests\Support\User;
use Classivo\Pdf\PdfServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            PdfServiceProvider::class,
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            CoreServiceProvider::class,
            HrServiceProvider::class,
            DomPdfServiceProvider::class,
            MediaLibraryServiceProvider::class,
            PermissionServiceProvider::class,
            Support\TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('classivo.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Host-app tables the package expects but does not ship: roles and media.
        foreach ([
            ['permissions', PermissionServiceProvider::class, 'database/migrations/create_permission_tables.php.stub'],
            ['media', MediaLibraryServiceProvider::class, 'database/migrations/create_media_table.php.stub'],
        ] as [$table, $provider, $stub]) {
            if (! Schema::hasTable($table)) {
                $migration = require dirname((new \ReflectionClass($provider))->getFileName(), 2).'/'.$stub;
                $migration->up();
            }
        }
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /**
     * A school with one default branch and a current session, pinned on the
     * context the way the web middleware would.
     *
     * @return array{0: School, 1: Branch, 2: AcademicSession}
     */
    protected function foundation(string $code = 'DEMO'): array
    {
        $school = School::create(['name' => 'Demo Public School', 'code' => $code, 'status' => true]);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main Campus', 'code' => 'MAIN', 'is_default' => true, 'status' => true]);
        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        config(['classivo.school_id' => $school->id]);
        app(CurrentContext::class)
            ->setSchoolId($school->id)
            ->setBranchId($branch->id)
            ->setSessionId($session->id);

        return [$school, $branch, $session];
    }

    /** A user holding exactly the given HR permissions (created on the fly). */
    protected function userWith(array $permissions = [], ?string $email = null): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'User '.$n,
            'email' => $email ?? "user{$n}@example.test",
            'password' => bcrypt('secret'),
            'school_id' => current_school_id(),
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    protected function adminUser(?School $school = null): User
    {
        return $this->userWith(HrPermissions::all(), 'admin@example.test');
    }
}
