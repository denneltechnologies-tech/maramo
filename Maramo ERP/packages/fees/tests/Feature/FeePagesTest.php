<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\CurrentContext;
use Classivo\Fees\Database\Seeders\FeesPermissionSeeder;
use Classivo\Fees\Filament\Pages\AssignFees;
use Classivo\Fees\Filament\Pages\CollectFees;
use Classivo\Fees\Filament\Pages\FeeReports;
use Classivo\Fees\Filament\Pages\FeesCarryForward;
use Classivo\Fees\Filament\Pages\FeesReminder;
use Classivo\Fees\Filament\Pages\FeesSessionRollover;
use Classivo\Fees\Filament\Pages\SearchDueFees;
use Classivo\Fees\Filament\Portal\Pages\ChildFees;
use Classivo\Fees\Models\FeeCarryForward;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Notifications\FeeReminderNotification;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\Reports\FeeReportService;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Tests\Support\StubUser;
use Classivo\Fees\Tests\UiTestCase;
use Classivo\Students\Models\Guardian;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class FeePagesTest extends UiTestCase
{
    public function test_counter_collection_end_to_end(): void
    {
        $this->userWith([FeesPermissions::COLLECT]);
        $student = $this->student('C100');
        $allocation = app(FeeAllocationService::class)->allocate($this->master(2500), $student);

        $page = Livewire::test(CollectFees::class)
            ->fillForm(['student_id' => $student->id])
            ->assertSet('data.summary_balance', '2500.00')
            ->assertSet('data.lines.0.balance', '2500.00');

        $page->call('payFull')
            ->fillForm(['mode' => 'cheque', 'reference_no' => 'CHQ-77'])
            ->call('collect')
            ->assertNotified();

        $payment = FeePayment::query()->sole();
        $this->assertSame('RCPT1', $payment->receipt_no);
        $this->assertSame('cheque', $payment->mode);
        $this->assertSame('CHQ-77', $payment->reference_no);
        $this->assertSame(2500.0, (float) $payment->paid_amount);
        $this->assertSame('paid', $allocation->fresh()->status);
        $page->assertSet('lastReceiptId', $payment->id)->assertSet('data.lines', []);
    }

    public function test_counter_partial_payment_and_stale_sheet_is_refused(): void
    {
        $this->userWith([FeesPermissions::COLLECT]);
        $student = $this->student('C200');
        $allocation = app(FeeAllocationService::class)->allocate($this->master(1000), $student);

        $page = Livewire::test(CollectFees::class)->fillForm(['student_id' => $student->id]);
        $page->set('data.lines.0.amount', 400)->call('collect');
        $this->assertSame(600.0, $allocation->fresh()->balance());

        // Someone else settles the line while this sheet still shows 600 owed.
        app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 600]]);
        $page->set('data.lines.0.amount', 600)->call('collect');

        $this->assertSame(2, FeePayment::count());
        $this->assertSame(0.0, $allocation->fresh()->balance());
    }

    public function test_counter_discount_needs_the_discount_permission(): void
    {
        $this->userWith([FeesPermissions::COLLECT]);
        $student = $this->student('C300');
        app(FeeAllocationService::class)->allocate($this->master(1000), $student);

        Livewire::test(CollectFees::class)
            ->fillForm(['student_id' => $student->id])
            ->set('data.lines.0.discount', 200)
            ->set('data.lines.0.amount', 800)
            ->set('data.discount_reason', 'Hardship')
            ->call('collect');

        $this->assertSame(0, FeePayment::count());
    }

    public function test_pages_are_denied_without_permission(): void
    {
        $this->userWith([]);

        foreach ([CollectFees::class, AssignFees::class, SearchDueFees::class, FeesReminder::class, FeesCarryForward::class, FeesSessionRollover::class, FeeReports::class] as $page) {
            $this->assertFalse($page::canAccess(), $page);
            $this->get($page::getUrl())->assertForbidden();
        }
    }

    public function test_assign_fees_raises_lines_for_the_class(): void
    {
        $this->userWith([FeesPermissions::ASSIGN]);
        $this->student('D1');
        $this->student('D2');
        $this->master(1500);

        Livewire::test(AssignFees::class)
            ->fillForm(['class_id' => $this->class->id])
            ->call('allocate')
            ->assertNotified();

        $this->assertSame(2, FeeStudentAllocation::count());
    }

    public function test_search_due_fees_and_reminder_board(): void
    {
        Notification::fake();
        $this->userWith([FeesPermissions::DUES_VIEW, FeesPermissions::REMINDERS_SEND, FeesPermissions::COLLECT]);
        $student = $this->student('E1', ['first_name' => 'Asha']);
        app(FeeAllocationService::class)->allocate($this->master(900, ['due_date' => now()->subDays(10)->toDateString()]), $student);

        Livewire::test(SearchDueFees::class)->assertOk()->assertSee('Asha')->assertSee('900.00');

        Livewire::test(FeesReminder::class)
            ->assertSee('Asha')
            ->call('sendReminder', $student->id)
            ->assertNotified();

        Notification::assertSentTimes(FeeReminderNotification::class, 1);

        // Within the cooldown the board refuses a second send.
        Livewire::test(FeesReminder::class)->call('sendReminder', $student->id);
        Notification::assertSentTimes(FeeReminderNotification::class, 1);
    }

    public function test_carry_forward_and_rollover_wizards(): void
    {
        $this->userWith([FeesPermissions::CARRY_FORWARD, FeesPermissions::ROLLOVER]);
        $student = $this->student('F1');
        app(FeeAllocationService::class)->allocate($this->master(700, ['class_id' => null]), $student);

        $old = $this->session;
        $new = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31', 'is_current' => false, 'status' => true]);
        app(CurrentContext::class)->setSessionId($new->id);

        Livewire::test(FeesSessionRollover::class)
            ->fillForm(['from_session_id' => $old->id, 'to_session_id' => $new->id, 'percent' => 10, 'rounding' => '0', 'carry_balances' => false])
            ->call('runPreview')
            ->assertSet('previewed', true)
            ->call('commitRollover')
            ->assertNotified();

        $this->assertSame(770.0, (float) FeeMaster::query()->withoutGlobalScopes()->where('session_id', $new->id)->value('amount'));

        Livewire::test(FeesCarryForward::class)
            ->fillForm(['from_session_id' => $old->id])
            ->call('loadBalances')
            ->assertSet('data.carries.0.amount', fn ($v) => (float) $v === 700.0)
            ->call('save')
            ->assertNotified();

        $this->assertSame(1, FeeCarryForward::query()->withoutGlobalScopes()->count());
    }

    public function test_fee_reports_render_every_report(): void
    {
        $this->userWith([FeesPermissions::REPORTS_VIEW]);
        $student = $this->student('G1');
        $allocation = app(FeeAllocationService::class)->allocate($this->master(1200, ['due_date' => now()->subDays(45)->toDateString()]), $student);
        app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 500]], ['mode' => 'bank_transfer']);

        $page = Livewire::test(FeeReports::class)->assertOk();

        foreach (array_keys(FeeReportService::REPORTS) as $report) {
            $page->fillForm(['report' => $report])->assertOk();
        }

        $ageing = app(FeeReportService::class)->run('ageing', ['as_of' => now()->toDateString()]);
        $this->assertSame(700.0, $ageing['totals']['outstanding']);

        $page->fillForm(['report' => 'collection'])->assertSee('RCPT1')->call('downloadCsv')->assertFileDownloaded();
    }

    public function test_guardian_portal_shows_only_linked_children(): void
    {
        $user = StubUser::create(['name' => 'Parent', 'email' => 'parent@example.test', 'password' => 'x']);
        $first = $this->student('H1', ['first_name' => 'Ravi']);
        $second = $this->student('H2', ['first_name' => 'Meera']);
        $other = $this->student('H3', ['first_name' => 'Zoya']);
        app(FeeAllocationService::class)->allocate($this->master(300), $first);

        $guardian = Guardian::create(['name' => 'Parent', 'relation' => 'father', 'email' => 'parent@example.test', 'user_id' => $user->id]);
        $guardian->students()->attach([$first->id, $second->id]);

        $this->actingAs($user);
        $this->assertTrue(ChildFees::canAccess());

        Livewire::test(ChildFees::class)
            ->assertSee('Ravi')
            ->assertSee('Meera')
            ->assertDontSee('Zoya')
            ->call('switchStudent', $other->id)
            ->assertSet('studentId', $first->id)
            ->call('switchStudent', $second->id)
            ->assertSet('studentId', $second->id);

        $this->actingAs(StubUser::create(['name' => 'Nobody', 'email' => 'nobody@example.test', 'password' => 'x']));
        $this->assertFalse(ChildFees::canAccess());
    }

    public function test_guardian_cannot_tamper_student_id_to_read_another_childs_fees(): void
    {
        $user = StubUser::create(['name' => 'Parent', 'email' => 'parent2@example.test', 'password' => 'x']);
        $mine = $this->student('J1', ['first_name' => 'Ravi']);
        $other = $this->student('J2', ['first_name' => 'Zoya']);
        app(FeeAllocationService::class)->allocate($this->master(300), $other);
        FeePayment::create([
            'student_id' => $other->id, 'receipt_no' => 'RCPT-SECRET-9', 'payment_date' => now()->toDateString(),
            'total_amount' => 100, 'paid_amount' => 100, 'mode' => 'cash', 'status' => 'completed',
        ]);

        $guardian = Guardian::create(['name' => 'Parent', 'relation' => 'father', 'email' => 'parent2@example.test', 'user_id' => $user->id]);
        $guardian->students()->attach([$mine->id]);
        $this->actingAs($user);

        $page = Livewire::test(ChildFees::class);
        try {
            $page->set('studentId', $other->id);
        } catch (\Throwable) {
            // A locked property refuses the update outright, which is the expected outcome.
        }

        $page->assertDontSee('RCPT-SECRET-9');
        $this->assertNotSame($other->id, $page->instance()->studentId);
        $this->assertCount(0, $page->instance()->receipts()->where('student_id', $other->id));
        $this->assertCount(0, $page->instance()->allocations()->where('student_id', $other->id));
    }

    public function test_permission_seeder_is_deny_by_default_and_grants_configured_roles(): void
    {
        Role::create(['name' => 'Accountant', 'guard_name' => 'web']);
        config(['fees.grant_to_roles' => ['Accountant']]);

        $this->seed(FeesPermissionSeeder::class);

        $accountant = StubUser::create(['name' => 'Acc', 'email' => 'acc@example.test', 'password' => 'x']);
        $accountant->assignRole('Accountant');
        $clerk = StubUser::create(['name' => 'Clerk', 'email' => 'clerk@example.test', 'password' => 'x']);

        foreach (FeesPermissions::all() as $permission) {
            $this->assertTrue($accountant->can($permission), $permission);
            $this->assertFalse($clerk->can($permission), $permission);
        }
    }

    public function test_counter_renders_in_hindi(): void
    {
        $this->userWith([FeesPermissions::COLLECT]);
        app()->setLocale('hi');

        Livewire::test(CollectFees::class)
            ->assertSee('शुल्क संग्रह')
            ->assertSee('विद्यार्थी खोजें (नाम / प्रवेश संख्या)');
    }
}
