<?php

namespace Classivo\Exams\Tests\Feature;

use Classivo\Exams\Support\Authorize;
use Classivo\Exams\Tests\Support\User;
use Classivo\Exams\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

class PermissionTest extends TestCase
{
    public function test_denied_by_default_for_guests_and_users_without_permission(): void
    {
        $this->assertFalse(Authorize::can('enter_marks'));
        $this->assertFalse(Authorize::can('publish_result'));

        $this->actingAs(User::create(['name' => 'Teacher']));

        $this->assertFalse(Authorize::can('enter_marks'));
        $this->assertFalse(Authorize::can('publish_result'));
    }

    public function test_allowed_with_spatie_permission_of_the_same_name(): void
    {
        Permission::create(['name' => 'exam.enter_marks', 'guard_name' => 'web']);
        $user = User::create(['name' => 'Teacher']);
        $user->givePermissionTo('exam.enter_marks');

        $this->actingAs($user);

        $this->assertTrue(Authorize::can('enter_marks'));
        $this->assertFalse(Authorize::can('publish_result'), 'Only the granted permission opens.');
    }

    public function test_missing_permission_row_is_denied_not_an_error(): void
    {
        $this->actingAs(User::create(['name' => 'Teacher']));

        $this->assertFalse(Gate::allows('exam.publish_result'));
    }

    public function test_host_defined_gate_wins(): void
    {
        Gate::define('exam.publish_result', fn ($user = null) => true);

        $this->assertTrue(Authorize::can('publish_result'));
    }
}
