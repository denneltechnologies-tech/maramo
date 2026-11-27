<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read-only demo login (viewer@example.com) can open the admin panel and every
 * screen it is allowed to list, but holds no create/edit/delete permission and is
 * refused on every create and edit page.
 */
class ReadOnlyViewerTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $this->seed();

        return User::where('email', 'viewer@example.com')->firstOrFail();
    }

    public function test_viewer_holds_only_read_permissions(): void
    {
        $viewer = $this->viewer();

        $this->assertTrue($viewer->hasRole('Viewer'));
        $names = $viewer->getAllPermissions()->pluck('name');
        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^reports\.|\.(view|view_results|dashboard|stats|print|download|export)$/', $name);
        }
        $this->assertSame($names->sort()->values()->all(), collect(RolesAndPermissionsSeeder::viewerPermissions($names->all()))->sort()->values()->all());
    }

    public function test_viewer_opens_the_admin_panel_and_its_screens(): void
    {
        $this->actingAs($this->viewer());
        $panel = Filament::getPanel('admin');

        $this->get('/admin')->assertOk();

        $opened = 0;
        foreach ($panel->getResources() as $resource) {
            if (! $resource::hasPage('index') || ! $resource::canViewAny()) {
                continue;
            }
            $this->get($resource::getUrl('index', panel: 'admin'))->assertOk();
            $opened++;
        }
        $this->assertGreaterThan(5, $opened);
    }

    public function test_viewer_is_refused_every_create_and_edit_page(): void
    {
        $this->actingAs($this->viewer());
        $panel = Filament::getPanel('admin');
        $checked = 0;

        foreach ($panel->getResources() as $resource) {
            $this->assertFalse($resource::canCreate(), $resource.' create');

            if ($resource::hasPage('create')) {
                $this->assertContains($this->get($resource::getUrl('create', panel: 'admin'))->status(), [403, 404], $resource);
                $checked++;
            }

            $record = $resource::getEloquentQuery()->withoutGlobalScopes()->first();
            if ($record) {
                $this->assertFalse($resource::canEdit($record), $resource.' edit');
                $this->assertFalse($resource::canDelete($record), $resource.' delete');
                if ($resource::hasPage('edit')) {
                    $this->assertContains($this->get($resource::getUrl('edit', ['record' => $record], panel: 'admin'))->status(), [403, 404], $resource);
                }
            }
        }
        $this->assertGreaterThan(5, $checked);
    }

    public function test_viewer_cannot_enter_marks(): void
    {
        $viewer = $this->viewer();

        $this->assertFalse($viewer->can('exam.enter_marks'));
        $this->assertFalse($viewer->can('exams.marks.any_class'));
    }
}
