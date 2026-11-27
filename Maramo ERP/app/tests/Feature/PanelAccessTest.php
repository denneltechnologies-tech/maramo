<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Every screen registered on the admin panel (by every Classivo plugin) loads
 * for the Admin role; users without it are refused.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    /**
     * @param  bool  $navigableOnly  skip pages that need a parameter (hidden from the menu)
     */
    private function screenUrls(string $panelId = 'admin', bool $navigableOnly = false): array
    {
        $panel = Filament::getPanel($panelId);
        $urls = [];

        foreach ($panel->getResources() as $resource) {
            if ($resource::hasPage('index')) {
                $urls[] = $resource::getUrl('index', panel: $panelId);
            }
        }

        foreach ($panel->getPages() as $page) {
            if ($navigableOnly && ! $page::shouldRegisterNavigation()) {
                continue;
            }

            $urls[] = $page::getUrl(panel: $panelId);
        }

        return $urls;
    }

    public function test_admin_role_holds_every_package_permission(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->hasRole('Admin'));
        $this->assertSame(Permission::count(), $admin->getAllPermissions()->count());
    }

    public function test_every_registered_screen_loads_for_the_admin(): void
    {
        $this->seed();
        $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());

        $urls = $this->screenUrls();
        $this->assertNotEmpty($urls);

        $navigable = $this->screenUrls(navigableOnly: true);

        foreach ($urls as $url) {
            $status = $this->get($url)->status();

            if (in_array($url, $navigable, true)) {
                $this->assertSame(200, $status, $url);
            } else {
                // Hidden pages may need a parameter (e.g. ?report=) and answer 404 without one.
                $this->assertContains($status, [200, 404], $url);
            }
        }
    }

    public function test_users_without_the_admin_role_are_denied(): void
    {
        $this->seed();

        $this->actingAs(User::where('email', 'teacher@example.com')->firstOrFail());
        $this->get('/admin')->assertForbidden();

        $nobody = User::create(['name' => 'No Role', 'email' => 'norole@example.com', 'password' => 'password']);
        $this->actingAs($nobody);

        foreach ($this->screenUrls() as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_student_portal_is_for_the_student_role_only(): void
    {
        $this->seed();

        $student = User::where('email', 'student@example.com')->firstOrFail();
        $this->actingAs($student);

        $urls = $this->screenUrls('student', navigableOnly: true);
        $this->assertNotEmpty($urls);

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/admin')->assertForbidden();

        $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail());
        $this->get('/student')->assertForbidden();
    }

    public function test_parent_portal_is_for_the_parent_role_only(): void
    {
        $this->seed();

        $this->actingAs(User::where('email', 'parent@example.com')->firstOrFail());

        $urls = $this->screenUrls('parent', navigableOnly: true);
        $this->assertNotEmpty($urls);

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/admin')->assertForbidden();
        $this->get('/student')->assertForbidden();

        $this->actingAs(User::where('email', 'student@example.com')->firstOrFail());
        $this->get('/parent')->assertForbidden();
    }
}
