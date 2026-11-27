<?php

namespace Classivo\DemoGuard\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Classivo\Core\CoreServiceProvider;
use Classivo\Core\Models\School;
use Classivo\DemoGuard\DemoGuardServiceProvider;
use Classivo\DemoGuard\Tests\Support\LockedSetting;
use Classivo\DemoGuard\Tests\Support\User;
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
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\PermissionServiceProvider;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        // classivo/payments is optional: its credentials are guarded only when installed.
        $payments = class_exists('Classivo\\Payments\\PaymentsServiceProvider') ? ['Classivo\\Payments\\PaymentsServiceProvider'] : [];

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
            PermissionServiceProvider::class,
            CoreServiceProvider::class,
            ...$payments,
            DemoGuardServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->instance('env', 'demo');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('classivo.user_model', User::class);
        // tests run in console; exercise the real guard
        $app['config']->set('demo-guard.allow_console', false);
        // a stand-in for any optional package's settings model
        $app['config']->set('demo-guard.locked_models', array_merge(
            (array) $app['config']->get('demo-guard.locked_models', []),
            [LockedSetting::class],
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        if (! Schema::hasTable('permissions')) {
            $m = require dirname((new \ReflectionClass(PermissionServiceProvider::class))->getFileName(), 2).'/database/migrations/create_permission_tables.php.stub';
            $m->up();
        }

        Schema::create('demo_guard_locked_settings', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function school(): School
    {
        return School::create(['name' => 'Demo Public School', 'code' => 'DEMO', 'status' => true]);
    }

    protected function user(): User
    {
        return User::create(['name' => 'Demo Admin', 'email' => 'admin@example.test', 'password' => 'secret-pass']);
    }
}
