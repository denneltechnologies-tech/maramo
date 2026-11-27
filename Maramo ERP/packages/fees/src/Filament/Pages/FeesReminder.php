<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Fees\Services\Fees\FeeDefaulterQuery;
use Classivo\Fees\Services\Fees\FeeReminderLadder;
use Classivo\Fees\Services\Fees\FeeReminderSender;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Fee reminder board — every overdue student with the ladder rung they have
 * reached, and buttons to e-mail one or all of them now. The same cooldown and
 * once-a-day rules as the scheduled `fees:send-reminders` command apply, so the
 * board and the scheduler can never double-message a family.
 */
class FeesReminder extends FeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 51;

    protected string $view = 'classivo-fees::filament.pages.fees-reminder';

    private const PAGE_LIMIT = 500;

    private ?Collection $rows = null;

    public static function permission(): string
    {
        return FeesPermissions::DUES_VIEW;
    }

    protected static function englishTitle(): string
    {
        return 'Fee Reminders';
    }

    /** @return Collection<int, object> */
    public function overdueRows(): Collection
    {
        return $this->rows ??= app(FeeDefaulterQuery::class)->page(self::PAGE_LIMIT);
    }

    public function canSendReminders(): bool
    {
        return FeesPermissions::can(FeesPermissions::REMINDERS_SEND);
    }

    public function rungLabel(object $row): string
    {
        $rung = app(FeeReminderLadder::class)->rungFor((int) $row->days_overdue);

        return $rung === null ? '—' : __(app(FeeReminderLadder::class)->label($rung));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendAll')
                ->label(__('Send reminder to all'))
                ->icon(Heroicon::OutlinedBellAlert)
                ->color('warning')
                ->visible(fn (): bool => $this->canSendReminders() && $this->overdueRows()->isNotEmpty())
                ->requiresConfirmation()
                ->modalHeading(__('Send fee reminders'))
                ->modalDescription(fn (): string => __(':count student(s) can be reminded now; the rest were reminded recently.', ['count' => $this->sendableRows()->count()]))
                ->action(fn () => $this->dispatchAll()),
        ];
    }

    public function sendReminder(int $studentId): void
    {
        if (! $this->canSendReminders()) {
            Notification::make()->danger()->title(__('You are not allowed to send fee reminders.'))->send();

            return;
        }

        $row = $this->overdueRows()->firstWhere('student_id', $studentId);

        if ($row === null || $row->student === null) {
            Notification::make()->warning()->title(__('That student is no longer overdue.'))->send();

            return;
        }

        $sender = app(FeeReminderSender::class);

        if ($sender->withinCooldown($row->student)) {
            Notification::make()->warning()->title(__('Already reminded recently. Nothing was sent.'))->send();

            return;
        }

        $sent = $sender->send(
            $row->student,
            (float) $row->due,
            app(FeeReminderLadder::class)->rungFor((int) $row->days_overdue),
            (int) $row->days_overdue,
            $row->oldest_due_date,
        );

        $this->report(1, $sent);
    }

    /** @return Collection<int, object> */
    private function sendableRows(): Collection
    {
        $rows = $this->overdueRows()->filter(fn (object $row): bool => $row->student !== null);
        $lastSent = app(FeeReminderSender::class)->lastRemindedForStudents($rows->pluck('student_id')->map(fn ($id): int => (int) $id)->all());
        $ladder = app(FeeReminderLadder::class);

        return $rows
            ->reject(fn (object $row): bool => $ladder->withinCooldown($lastSent->get((int) $row->student_id))
                || $ladder->announcedToday($lastSent->get((int) $row->student_id)))
            ->values();
    }

    public function dispatchAll(): void
    {
        if (! $this->canSendReminders()) {
            Notification::make()->danger()->title(__('You are not allowed to send fee reminders.'))->send();

            return;
        }

        $rows = $this->sendableRows();

        if ($rows->isEmpty()) {
            Notification::make()->warning()->title(__('Nothing to send — every overdue student was reminded recently.'))->send();

            return;
        }

        $ladder = app(FeeReminderLadder::class);
        $sender = app(FeeReminderSender::class);
        FeeReminderLadder::resetRunState();
        $students = 0;
        $emails = 0;

        foreach ($rows as $row) {
            if ($ladder->ceilingReached()) {
                break;
            }

            $emails += $sender->send($row->student, (float) $row->due, $ladder->rungFor((int) $row->days_overdue), (int) $row->days_overdue, $row->oldest_due_date, force: true);
            $ladder->countDispatch();
            $students++;
        }

        $this->rows = null;
        $this->report($students, $emails);
    }

    private function report(int $students, int $emails): void
    {
        if ($emails === 0) {
            Notification::make()->warning()
                ->title(__('No e-mail was sent.'))
                ->body(__('No guardian or student e-mail address is on file for the selected student(s).'))
                ->send();

            return;
        }

        Notification::make()->success()
            ->title(__('Reminders sent: :students student(s), :emails e-mail(s).', ['students' => $students, 'emails' => $emails]))
            ->send();
    }
}
