<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Core\Models\Branch;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Notifications\FeeReminderNotification;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Services\FeeCollectionService;
use Classivo\Fees\Services\Fees\FeeDefaulterQuery;
use Classivo\Fees\Services\Fees\FeeReminderLadder;
use Classivo\Fees\Services\FeeStatementService;
use Classivo\Fees\Support\Money;
use Classivo\Fees\Tests\Support\StubUser;
use Classivo\Fees\Tests\TestCase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

class RemindersAndReceiptsTest extends TestCase
{
    public function test_ladder_rungs(): void
    {
        $ladder = app(FeeReminderLadder::class);

        $this->assertNull($ladder->rungFor(2));
        $this->assertSame(3, $ladder->rungFor(3));
        $this->assertSame(7, $ladder->rungFor(10));
        $this->assertSame(30, $ladder->rungFor(45));
        $this->assertNull($ladder->rungFor(500));
    }

    public function test_defaulter_query_and_reminder_command_send_mail_once(): void
    {
        Notification::fake();

        $student = $this->student('A100');
        app(FeeAllocationService::class)->allocate($this->master(1200, ['due_date' => now()->subDays(8)->toDateString()]), $student);
        $this->student('A101'); // nothing owed

        $rows = app(FeeDefaulterQuery::class)->page();
        $this->assertCount(1, $rows);
        $this->assertSame(1200.0, $rows->first()->due);
        $this->assertSame('Grade 5', $rows->first()->class_section);

        $this->artisan('fees:send-reminders')->assertSuccessful();

        Notification::assertSentTo(new AnonymousNotifiable, FeeReminderNotification::class, function (FeeReminderNotification $n, array $channels, AnonymousNotifiable $notifiable): bool {
            return $channels === ['mail']
                && array_key_exists('a100@example.test', (array) $notifiable->routes['mail'])
                && str_contains($n->subject, 'Fee overdue');
        });
        $this->assertSame(7, (int) FeeStudentAllocation::query()->value('reminder_stage'));

        // Same rung, same day: nothing more is sent.
        $this->artisan('fees:send-reminders')->assertSuccessful();
        Notification::assertSentTimes(FeeReminderNotification::class, 1);
    }

    public function test_dry_run_sends_nothing(): void
    {
        Notification::fake();

        $student = $this->student('A200');
        app(FeeAllocationService::class)->allocate($this->master(900, ['due_date' => now()->subDays(4)->toDateString()]), $student);

        $this->artisan('fees:send-reminders', ['--dry-run' => true])->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull(FeeStudentAllocation::query()->value('reminder_stage'));
    }

    public function test_receipt_pdf_and_currency_defaults(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(2800), $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 2800]]);

        $statement = app(FeeStatementService::class);
        $this->assertStringStartsWith('%PDF', $statement->receiptPdf($payment)->output());

        $this->assertSame('INR', Money::code());
        $this->assertSame('₹', Money::symbol());

        // A branch without its own currency falls back to the active preset.
        config(['classivo.preset' => 'generic']);
        $this->assertSame('USD', Money::code(new Branch));
        $this->assertSame('$', Money::symbol(new Branch));
    }

    public function test_receipt_route_is_gated(): void
    {
        $student = $this->student();
        $allocation = app(FeeAllocationService::class)->allocate($this->master(500), $student);
        $payment = app(FeeCollectionService::class)->collect($student, [['allocation_id' => $allocation->id, 'amount' => 500]]);

        $owner = StubUser::create(['name' => 'Student', 'email' => 's@example.test', 'password' => 'x']);
        $student->forceFill(['user_id' => $owner->id])->save();

        $stranger = StubUser::create(['name' => 'Other', 'email' => 'o@example.test', 'password' => 'x']);

        $this->actingAs($stranger)->get('/admin/fees/receipt/'.$payment->id)->assertForbidden();
        $this->actingAs($owner)->get('/admin/fees/receipt/'.$payment->id)->assertOk();
    }
}
