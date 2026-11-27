<?php

namespace Classivo\Core\Tests\Feature;

use Classivo\Core\Filament\Resources\BranchResource;
use Classivo\Core\Filament\Resources\Pages\ManageBranches;
use Classivo\Core\Filament\Resources\Pages\ManageSections;
use Classivo\Core\Filament\Resources\SectionResource;
use Classivo\Core\Filament\Resources\SubjectResource;
use Classivo\Core\Models\Section;
use Classivo\Core\Support\Permissions;
use Classivo\Core\Tests\PanelTestCase;
use Classivo\Core\Tests\Support\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class PanelAuthorizationTest extends PanelTestCase
{
    /** @param list<string> $granted */
    private function signIn(array $granted): void
    {
        foreach (Permissions::all() as $name) {
            Gate::define($name, fn ($user) => in_array($name, $granted, true));
        }

        static $n = 0;
        $n++;
        $this->actingAs(User::create(['name' => 'U'.$n, 'email' => "u{$n}@example.test", 'password' => 'x']));
        Filament::setCurrentPanel('admin');
    }

    public function test_direct_urls_are_forbidden_without_permission(): void
    {
        $this->foundation();
        $this->signIn([]);

        $this->get(BranchResource::getUrl('index'))->assertForbidden();
        $this->get(SectionResource::getUrl('index'))->assertForbidden();
        $this->get(SubjectResource::getUrl('index'))->assertForbidden();
        Livewire::test(ManageBranches::class)->assertForbidden();
    }

    public function test_view_only_user_cannot_create_edit_delete_or_toggle(): void
    {
        $this->foundation();
        $section = Section::create(['name' => 'A', 'status' => true]);
        $this->signIn(['core.sections.view']);

        $this->get(SectionResource::getUrl('index'))->assertOk();

        Livewire::test(ManageSections::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $section)
            ->assertTableActionHidden('delete', $section)
            ->call('updateTableColumnState', 'status', (string) $section->getKey(), false);

        $this->assertTrue((bool) $section->fresh()->status, 'toggle must not write without update permission');
    }

    public function test_full_permission_user_can_create(): void
    {
        $this->foundation();
        $this->signIn(Permissions::all());

        Livewire::test(ManageSections::class)
            ->callAction('create', ['name' => 'B'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Section::query()->where('name', 'B')->exists());
    }
}
