<?php

namespace Classivo\Hr\Tests\Feature;

use Classivo\Hr\Filament\Resources\Staff\Actions\DocumentDownloadActions;
use Classivo\Hr\Filament\Resources\Staff\Pages\CreateStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\EditStaff;
use Classivo\Hr\Filament\Resources\Staff\Pages\ViewStaff;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffDocument;
use Classivo\Hr\Support\HrPermissions as P;
use Classivo\Hr\Tests\Support\User;
use Classivo\Hr\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/** SECURITY-REVIEW H1 (role escalation, account takeover) and M-level document handling. */
class SecurityTest extends TestCase
{
    private function role(string $name): Role
    {
        return Role::findOrCreate($name, 'web');
    }

    private function staffFor(User $user, array $extra = []): Staff
    {
        return Staff::create(array_merge([
            'first_name' => 'Self', 'joining_date' => '2028-01-01', 'basic_salary' => 1000, 'status' => 'active',
            'user_id' => $user->id, 'email' => $user->email,
        ], $extra));
    }

    public function test_update_permission_alone_cannot_assign_a_role(): void
    {
        $this->foundation();
        $admin = $this->role('Admin');
        $clerk = $this->userWith([P::STAFF_VIEW, P::STAFF_UPDATE]);
        $own = $this->staffFor($clerk);

        try {
            Livewire::test(EditStaff::class, ['record' => $own->getKey()])
                ->fillForm(['role_id' => $admin->id])
                ->call('save');
        } catch (\Throwable) {
        }

        $this->assertNull($own->fresh()->role_id);
        $this->assertFalse($clerk->fresh()->hasRole('Admin'));
    }

    public function test_model_refuses_a_role_change_without_the_assign_permission(): void
    {
        $this->foundation();
        $admin = $this->role('Admin');
        $clerk = $this->userWith([P::STAFF_UPDATE]);
        $own = $this->staffFor($clerk);

        $this->expectException(AuthorizationException::class);
        $own->update(['role_id' => $admin->id]);
    }

    public function test_assign_roles_permission_still_cannot_grant_a_role_the_actor_lacks(): void
    {
        $this->foundation();
        $admin = $this->role('Admin');
        $teacher = $this->role('Teacher');
        $hr = $this->userWith([P::STAFF_UPDATE, P::STAFF_ASSIGN_ROLES]);
        $hr->assignRole('Teacher');
        $own = $this->staffFor($hr);

        // A role the actor holds is grantable...
        $own->update(['role_id' => $teacher->id]);
        $this->assertSame($teacher->id, $own->fresh()->role_id);

        // ...Admin is not, unless the actor is Admin.
        try {
            $own->update(['role_id' => $admin->id]);
            $this->fail('Admin must not be grantable');
        } catch (AuthorizationException) {
        }
        $this->assertFalse($hr->fresh()->hasRole('Admin'));

        $hr->assignRole('Admin');
        $own->update(['role_id' => $admin->id]);
        $this->assertSame($admin->id, $own->fresh()->role_id);
    }

    public function test_enabling_login_never_adopts_an_existing_account_or_resets_its_password(): void
    {
        $this->foundation();
        $victim = User::create(['name' => 'Real Admin', 'email' => 'boss@example.test', 'password' => Hash::make('original-pass')]);
        $victim->assignRole($this->role('Admin'));

        $this->userWith([P::STAFF_VIEW, P::STAFF_CREATE, P::STAFF_MANAGE_LOGIN]);
        $staff = Staff::create(['first_name' => 'Evil', 'joining_date' => '2028-01-01', 'basic_salary' => 1, 'status' => 'active', 'email' => 'boss@example.test']);

        try {
            $staff->provisionLogin('pwned123');
            $this->fail('Existing account must not be adopted');
        } catch (\DomainException) {
        }

        $this->assertNull($staff->fresh()->user_id);
        $this->assertTrue(Hash::check('original-pass', $victim->fresh()->password));
        $this->assertFalse(Hash::check('pwned123', $victim->fresh()->password));
    }

    public function test_the_create_form_rejects_an_email_that_belongs_to_an_existing_account(): void
    {
        $this->foundation();
        $this->withoutVite();
        $victim = User::create(['name' => 'Real Admin', 'email' => 'boss@example.test', 'password' => Hash::make('original-pass')]);
        $this->userWith([P::STAFF_VIEW, P::STAFF_CREATE, P::STAFF_MANAGE_LOGIN]);

        Livewire::test(CreateStaff::class)
            ->fillForm(['first_name' => 'Evil', 'joining_date' => '2028-01-01', 'email' => 'boss@example.test', 'enable_login' => true, 'login_password' => 'pwned123'])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $this->assertSame(0, Staff::where('first_name', 'Evil')->count());
        $this->assertTrue(Hash::check('original-pass', $victim->fresh()->password));
    }

    public function test_an_existing_login_is_never_given_a_new_password_or_email_by_staff_edits(): void
    {
        $this->foundation();
        $this->userWith([P::STAFF_UPDATE, P::STAFF_MANAGE_LOGIN]);
        $staff = Staff::create(['first_name' => 'Linked', 'joining_date' => '2028-01-01', 'basic_salary' => 1, 'status' => 'active', 'email' => 'linked@example.test']);
        $user = $staff->provisionLogin('first-pass');
        $hash = $user->fresh()->password;

        $staff->update(['email' => 'attacker@example.test']);
        $staff->fresh()->provisionLogin('second-pass');

        $this->assertSame($hash, $user->fresh()->password, 'password is never reset silently');
        $this->assertSame('linked@example.test', $user->fresh()->email, 'login email is not rewritten from the staff record');
    }

    public function test_staff_documents_accept_only_safe_types_on_a_private_disk_and_download_is_authorised(): void
    {
        $this->foundation();
        Storage::fake('local');
        $form = (string) file_get_contents(__DIR__.'/../../src/Filament/Resources/Staff/Schemas/StaffForm.php');
        $this->assertStringContainsString("acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])", $form);
        $this->assertStringContainsString("->visibility('private')", $form);

        $this->userWith([P::STAFF_VIEW]);
        $staff = Staff::create(['first_name' => 'Doc', 'joining_date' => '2028-01-01', 'basic_salary' => 1, 'status' => 'active']);
        Storage::disk('local')->put('staff-documents/cert.pdf', '%PDF-1.4 test');
        Storage::disk('local')->put('staff-documents/evil.html', '<script>alert(1)</script>');
        $ok = StaffDocument::create(['staff_id' => $staff->id, 'title' => 'Cert', 'file_path' => 'staff-documents/cert.pdf']);
        $bad = StaffDocument::create(['staff_id' => $staff->id, 'title' => 'Evil', 'file_path' => 'staff-documents/evil.html']);

        $this->userWith([]);
        $this->get(route('hr.staff-documents.download', $ok))->assertForbidden();

        $this->userWith([P::STAFF_VIEW]);
        $response = $this->get(route('hr.staff-documents.download', $ok));
        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        // A stored file with a disallowed type is never served.
        $this->get(route('hr.staff-documents.download', $bad))->assertNotFound();
    }

    public function test_the_staff_member_can_download_their_own_document(): void
    {
        $this->foundation();
        Storage::fake('local');
        $user = $this->userWith([]);
        $staff = $this->staffFor($user);
        Storage::disk('local')->put('staff-documents/mine.png', 'png');
        $doc = StaffDocument::create(['staff_id' => $staff->id, 'title' => 'Mine', 'file_path' => 'staff-documents/mine.png']);

        $this->get(route('hr.staff-documents.download', $doc))->assertOk();
    }

    public function test_document_download_links_show_on_view_and_edit_pages_only_when_authorised(): void
    {
        $this->foundation();
        $this->withoutVite();
        Storage::fake('local');
        $staff = Staff::create(['first_name' => 'Docs', 'joining_date' => '2028-01-01', 'basic_salary' => 1, 'status' => 'active']);
        Storage::disk('local')->put('staff-documents/a.pdf', '%PDF');
        $doc = StaffDocument::create(['staff_id' => $staff->id, 'title' => 'Certificate', 'file_path' => 'staff-documents/a.pdf']);
        StaffDocument::create(['staff_id' => $staff->id, 'title' => 'Empty', 'file_path' => null]);
        $name = 'download_document_'.$doc->id;
        $url = route('hr.staff-documents.download', $doc);

        $this->userWith([P::STAFF_VIEW, P::STAFF_UPDATE]);
        Livewire::test(ViewStaff::class, ['record' => $staff->getKey()])
            ->assertSee($url, false);
        Livewire::test(EditStaff::class, ['record' => $staff->getKey()])->assertSee($url, false)->assertDontSee('download_document_'.($doc->id + 1));

        // Not authorised for the files: the menu is not rendered.
        $this->userWith([P::STAFF_UPDATE]);
        $this->assertFalse(DocumentDownloadActions::authorised($staff));
        $this->assertFalse(DocumentDownloadActions::make($staff)->isVisible());
    }
}
