<?php

namespace Classivo\DemoGuard\Tests\Feature;

use Classivo\Core\Models\School;
use Classivo\DemoGuard\DemoGuard;
use Classivo\DemoGuard\Exceptions\DemoRestricted;
use Classivo\DemoGuard\Http\Middleware\LimitDemoUploads;
use Classivo\DemoGuard\Tests\Support\LockedSetting;
use Classivo\DemoGuard\Tests\TestCase;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DemoGuardTest extends TestCase
{
    public function test_user_email_and_password_cannot_change(): void
    {
        $u = DemoGuard::bypass(fn () => $this->user());
        $this->expectException(DemoRestricted::class);
        $u->update(['password' => 'new-password']);
    }

    public function test_user_email_cannot_change(): void
    {
        $u = DemoGuard::bypass(fn () => $this->user());
        $this->expectException(DemoRestricted::class);
        $u->update(['email' => 'other@example.test']);
    }

    public function test_two_factor_cannot_be_switched_on_for_a_shared_account(): void
    {
        $u = DemoGuard::bypass(fn () => $this->user());
        $this->expectException(DemoRestricted::class);
        $u->forceFill(['app_authentication_secret' => 'ABCDEF'])->save();
    }

    public function test_user_name_can_change_but_not_delete(): void
    {
        $u = DemoGuard::bypass(fn () => $this->user());
        $u->update(['name' => 'Renamed']);
        $this->assertSame('Renamed', $u->fresh()->name);
        $this->expectException(DemoRestricted::class);
        $u->delete();
    }

    public function test_roles_and_permissions_locked(): void
    {
        $role = DemoGuard::bypass(fn () => Role::create(['name' => 'Teacher', 'guard_name' => 'web']));
        $perm = DemoGuard::bypass(fn () => Permission::create(['name' => 'x.view', 'guard_name' => 'web']));
        foreach ([fn () => $role->delete(), fn () => $role->update(['name' => 'T2']), fn () => $perm->delete()] as $op) {
            try {
                $op();
                $this->fail('expected block');
            } catch (DemoRestricted $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_school_is_locked(): void
    {
        $school = DemoGuard::bypass(fn () => $this->school());
        $this->expectException(DemoRestricted::class);
        $school->update(['name' => 'Hacked']);
    }

    public function test_locked_models_refuse_create_update_and_delete(): void
    {
        $setting = DemoGuard::bypass(fn () => LockedSetting::create(['name' => 'sms']));

        foreach ([fn () => LockedSetting::create(['name' => 'x']), fn () => $setting->update(['name' => 'y']), fn () => $setting->delete()] as $write) {
            try {
                $write();
                $this->fail('expected block');
            } catch (DemoRestricted) {
            }
        }

        $this->assertSame('sms', $setting->fresh()->name);
    }

    public function test_payment_credentials_locked_when_payments_is_installed(): void
    {
        $model = 'Classivo\\Payments\\Models\\SchoolPaymentCredential';
        if (! class_exists($model)) {
            $this->markTestSkipped('classivo/payments is not installed.');
        }

        $school = DemoGuard::bypass(fn () => $this->school());
        $this->expectException(DemoRestricted::class);
        $model::withoutGlobalScopes()->create(['school_id' => $school->id, 'gateway' => 'razorpay', 'mode' => 'test']);
    }

    public function test_console_is_exempt_when_allowed(): void
    {
        config(['demo-guard.allow_console' => true]);
        $school = DemoGuard::bypass(fn () => $this->school());
        $school->update(['name' => 'Seeded Again']);
        $this->assertSame('Seeded Again', School::find($school->id)->name);
    }

    public function test_disabled_outside_demo(): void
    {
        app()->instance('env', 'production');
        $this->assertFalse(DemoGuard::active());
        $u = $this->user();
        $u->update(['email' => 'free@example.test']);
        $this->assertSame('free@example.test', $u->fresh()->email);
    }

    public function test_upload_cap(): void
    {
        $mw = new LimitDemoUploads;
        $big = UploadedFile::fake()->create('big.pdf', 3000);
        $req = Request::create('/x', 'POST', [], [], ['f' => $big]);
        try {
            $mw->handle($req, fn () => response('ok'));
            $this->fail('big upload allowed');
        } catch (HttpException $e) {
            $this->assertSame(413, $e->getStatusCode());
        }
        $small = UploadedFile::fake()->create('s.pdf', 100);
        $res = $mw->handle(Request::create('/x', 'POST', [], [], ['f' => $small]), fn () => response('ok'));
        $this->assertSame('ok', $res->getContent());
    }

    public function test_banner_renders_with_reset_time(): void
    {
        $html = view('demo-guard::banner', ['resetTime' => '03:00 IST'])->render();
        $this->assertStringContainsString('Demo - resets nightly at 03:00 IST', $html);
    }

    public function test_banner_hook_is_registered_on_panel_body(): void
    {
        $hooks = FilamentView::renderHook(PanelsRenderHook::BODY_START);
        $this->assertStringContainsString('resets nightly', (string) $hooks);
    }
}
