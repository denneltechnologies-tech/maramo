<?php

namespace Classivo\Core\Tests\Feature;

use Classivo\Core\Database\Seeders\CorePermissionSeeder;
use Classivo\Core\Filament\Resources\AcademicSessionResource;
use Classivo\Core\Filament\Resources\BranchResource;
use Classivo\Core\Filament\Resources\SchoolClassResource;
use Classivo\Core\Filament\Resources\SectionResource;
use Classivo\Core\Filament\Resources\SubjectResource;
use Classivo\Core\Support\Permissions;
use Classivo\Core\Tests\Support\User;
use Classivo\Core\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

class ResourceAuthorizationTest extends TestCase
{
    private const RESOURCES = [
        'core.branches' => BranchResource::class,
        'core.academic_sessions' => AcademicSessionResource::class,
        'core.classes' => SchoolClassResource::class,
        'core.sections' => SectionResource::class,
        'core.subjects' => SubjectResource::class,
    ];

    private function user(): User
    {
        static $n = 0;
        $n++;

        return User::create(['name' => 'User '.$n, 'email' => "user{$n}@example.test", 'password' => 'secret']);
    }

    public function test_every_core_resource_is_denied_without_permission(): void
    {
        [, $branch] = $this->foundation();

        foreach (self::RESOURCES as $resource) {
            $this->assertFalse($resource::canViewAny(), 'guest '.$resource);
        }

        $this->actingAs($this->user());

        foreach (self::RESOURCES as $resource) {
            $this->assertFalse($resource::canViewAny(), $resource);
            $this->assertFalse($resource::canCreate(), $resource);
            $this->assertFalse($resource::canDeleteAny(), $resource);
        }
        $this->assertFalse(BranchResource::canEdit($branch));
        $this->assertFalse(BranchResource::canDelete($branch));
    }

    public function test_each_ability_maps_to_its_own_permission(): void
    {
        [, $branch] = $this->foundation();
        $granted = ['core.branches.view', 'core.branches.update'];
        foreach (Permissions::all() as $name) {
            Gate::define($name, fn ($user) => in_array($name, $granted, true));
        }

        $this->actingAs($this->user());

        $this->assertTrue(BranchResource::canViewAny());
        $this->assertTrue(BranchResource::canEdit($branch));
        $this->assertFalse(BranchResource::canCreate());
        $this->assertFalse(BranchResource::canDelete($branch));
        $this->assertFalse(SubjectResource::canViewAny(), 'no leakage across resources');
    }

    public function test_permission_list_names_every_resource_ability(): void
    {
        foreach (array_keys(self::RESOURCES) as $prefix) {
            foreach (['view', 'create', 'update', 'delete'] as $ability) {
                $this->assertContains($prefix.'.'.$ability, Permissions::all());
            }
        }

        // Without spatie/laravel-permission installed the seeder is a safe no-op.
        $this->seed(CorePermissionSeeder::class);
        $this->assertTrue(true);
    }
}
