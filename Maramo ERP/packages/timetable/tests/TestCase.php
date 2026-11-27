<?php

namespace Classivo\Timetable\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Timetable\Tests\Support\TestPanelProvider;
use Classivo\Timetable\Tests\Support\User;
use Classivo\Timetable\TimetableServiceProvider;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected School $school;

    protected Branch $branch;

    protected AcademicSession $session;

    protected function getPackageProviders($app): array
    {
        return [
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
            TimetableServiceProvider::class,
            PermissionServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('classivo.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        // The host application owns `users` (teacher_id points at it).
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('password')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            $migration = require dirname((new \ReflectionClass(PermissionServiceProvider::class))->getFileName(), 2).'/database/migrations/create_permission_tables.php.stub';
            $migration->up();
        }
    }

    /** Act as a fresh user holding exactly these permissions. */
    protected function actingWith(string ...$permissions): User
    {
        $user = $this->teacher('Staff '.uniqid());
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }
        $this->actingAs($user);

        return $user;
    }

    /** A school with a default branch and current session pinned on the context. */
    protected function foundation(): void
    {
        $this->school = School::create(['name' => 'Demo Public School', 'code' => 'DEMO', 'status' => true]);
        $this->branch = Branch::create(['school_id' => $this->school->id, 'name' => 'Main Campus', 'code' => 'MAIN', 'is_default' => true, 'status' => true]);
        $this->session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026-2027', 'is_current' => true, 'status' => true]);

        config(['classivo.school_id' => $this->school->id]);
        current_context()
            ->setSchoolId($this->school->id)
            ->setBranchId($this->branch->id)
            ->setSessionId($this->session->id);
    }

    /** @param list<string> $sections @return list<ClassSection> */
    protected function classWithSections(string $name, int $order, array $sections): array
    {
        $class = SchoolClass::create(['name' => $name, 'numeric_order' => $order, 'status' => true]);
        $ids = [];
        foreach ($sections as $sectionName) {
            $ids[] = (Section::firstWhere('name', $sectionName) ?? Section::create(['name' => $sectionName, 'status' => true]))->id;
        }
        $class->syncSections($ids);

        return ClassSection::where('class_id', $class->id)->orderBy('section_id')->get()->all();
    }

    protected function subject(string $name): Subject
    {
        return Subject::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)), 'type' => 'theory', 'status' => true]);
    }

    protected function teacher(string $name): User
    {
        return User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test']);
    }
}
