<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscountStudents\Pages;

use Classivo\Fees\Filament\Resources\FeeDiscountStudents\FeeDiscountStudentResource;
use Classivo\Fees\Models\FeeDiscountStudent;
use Classivo\Fees\Services\ConcessionService;
use Classivo\Fees\Support\DiscountApprovalPolicy;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Grant a concession.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS PAGE DOES NOT JUST SAVE THE FORM
 * ─────────────────────────────────────────────────────────────────────────────
 * Filament's default create writes the model and stops. That would produce a
 * register row that reduces nobody's bill — the money only moves through
 * {@see FeeDiscountStudent::apply()} — and it would bypass the threshold entirely,
 * because deciding whether a grant is over the school's limit is
 * {@see ConcessionService::grant()}'s job and nothing else's.
 *
 * So the whole of creation is handed to the service, which is the SAME call the
 * concession would go through from anywhere else. `handleRecordCreation()` is the
 * documented seam for exactly this; overriding it (rather than doing the work in an
 * `after` hook) keeps the record Filament then redirects to the record the service
 * actually made.
 *
 * The notification tells the operator which of the two things happened, because
 * "saved" is not an answer when the answer might be "and it is now waiting for the
 * principal".
 */
class CreateFeeDiscountStudent extends CreateRecord
{
    protected static string $resource = FeeDiscountStudentResource::class;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->can('fees.discount'), 403);

        parent::mount();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(ConcessionService::class)->grant($data, auth()->user());
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var FeeDiscountStudent $record */
        $record = $this->getRecord();

        if ($record->isPendingApproval()) {
            return Notification::make()
                ->warning()
                ->title(__('Concession recorded — awaiting approval'))
                ->body(DiscountApprovalPolicy::summary().' Nothing has come off the student\'s bill yet.');
        }

        return Notification::make()
            ->success()
            ->title(__('Concession granted'))
            ->body($record->isApplied()
                ? __('It has been deducted from the fee line.')
                : __('It will come off every fee line raised for this student from now on.'));
    }
}
