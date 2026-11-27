<?php

namespace Classivo\DemoGuard\Tests\Feature;

use Classivo\DemoGuard\DemoGuard;
use Classivo\DemoGuard\DemoGuardServiceProvider;
use Classivo\DemoGuard\Exceptions\DemoRestricted;
use Classivo\DemoGuard\Tests\Support\User;
use Classivo\DemoGuard\Tests\TestCase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ReadOnlyRoleTest extends TestCase
{
    private function viewer(): User
    {
        return DemoGuard::bypass(function () {
            $role = Role::findOrCreate('Viewer', 'web');
            $user = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => 'password']);
            $user->assignRole($role);

            return $user;
        });
    }

    public function test_viewer_cannot_create_update_or_delete_records(): void
    {
        $other = DemoGuard::bypass(fn () => $this->user());
        $this->actingAs($this->viewer());

        $writes = [
            fn () => User::create(['name' => 'New', 'email' => 'new@example.com', 'password' => 'x']),
            fn () => $other->update(['name' => 'Changed']),
            fn () => Role::create(['name' => 'Extra', 'guard_name' => 'web']),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                $this->fail('expected the read-only guard to block this write');
            } catch (DemoRestricted $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('Demo Admin', $other->fresh()->name);
    }

    public function test_viewer_sign_in_bookkeeping_is_allowed(): void
    {
        $viewer = $this->viewer();
        $this->actingAs($viewer);

        $viewer->setRememberToken(Str::random(60));
        $viewer->save();

        $this->assertNotNull($viewer->fresh()->getRememberToken());
    }

    public function test_other_signed_in_users_are_not_affected(): void
    {
        $user = DemoGuard::bypass(fn () => $this->user());
        $this->actingAs($user);

        $created = User::create(['name' => 'New', 'email' => 'new2@example.com', 'password' => 'x']);
        $this->assertTrue($created->exists);
    }

    public function test_login_page_shows_the_configured_demo_logins(): void
    {
        $html = DemoGuardServiceProvider::loginsHtml('admin');
        $this->assertStringContainsString('viewer@example.com', $html);
        $this->assertStringContainsString('password', $html);

        $this->assertSame('', DemoGuardServiceProvider::loginsHtml('nope'));
        $this->assertSame('', DemoGuardServiceProvider::loginsHtml(null));
    }
}
