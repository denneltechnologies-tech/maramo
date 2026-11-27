<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscountStudents\Tables;

use Classivo\Fees\Models\FeeDiscountStudent;
use Classivo\Fees\Services\ConcessionService;
use Classivo\Fees\Support\DiscountApprovalPolicy;
use Classivo\Fees\Support\Labels;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * The concession register list — who holds which concession, when it
 * was granted, by whom, why, and whether it has actually come off the bill.
 *
 * Approve / reject go through {@see static::decide()}, which locks the row and
 * refuses a decision that has already been taken.
 *
 * The modal descriptions say WHAT WILL HAPPEN TO THE MONEY, in both directions,
 * because "approve" on this screen is not a filing action: it is the moment a
 * student's outstanding balance falls.
 */
class FeeDiscountStudentsTable
{
    /** Granting, approving and refusing a concession are one privilege. */
    protected static function mayDecide(): bool
    {
        return (bool) auth()->user()?->can('fees.discount');
    }

    /**
     * Approve or refuse one concession, exactly once: the row is re-read under a
     * lock and a decision that has already been taken is refused, so a
     * double-click's second attempt throws and changes nothing. Refusing an
     * already-APPROVED concession is allowed here (it reverses the discount).
     * A refusal records who refused it in the remark, because reject() clears
     * the approval columns.
     */
    protected static function decide(FeeDiscountStudent $record, string $decision, ?string $remark): void
    {
        $actor = auth()->user();

        if ($actor === null) {
            return;
        }

        DB::transaction(function () use ($record, $actor, $decision, $remark): void {
            $locked = FeeDiscountStudent::query()->lockForUpdate()->find($record->id);

            if (! $locked instanceof FeeDiscountStudent) {
                throw new \RuntimeException(__('That concession no longer exists.'));
            }

            $already = $decision === 'approved' ? $locked->isApproved() : $locked->isRejected();

            if ($already) {
                throw new \RuntimeException(__('This concession has already been ').$decision.'.');
            }

            if ($decision === 'approved') {
                $locked->approve($actor);
            } else {
                $who = (string) ($actor->name ?? ('user #'.$actor->getAuthIdentifier()));
                $locked->reject($actor, trim(__('Refused by ').$who.': '.(string) $remark));
            }
        });

        $record->refresh();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student.full_name')
                    ->label(__('Student'))
                    // Not sortable: `full_name` is an accessor, not a column.
                    ->searchable(['first_name', 'last_name'])
                    ->description(fn (FeeDiscountStudent $record): ?string => $record->student?->admission_no),
                TextColumn::make('discount.name')
                    ->label(__('Concession'))
                    // A hand-typed grant has no catalogue row; saying so beats a
                    // blank cell that reads as missing data.
                    ->placeholder(__('One-off (not in catalogue)'))
                    ->searchable(),
                TextColumn::make('amount')
                    ->label(__('Value'))
                    ->formatStateUsing(fn ($state, FeeDiscountStudent $record): string => $record->isRecurring() && (float) $state <= 0
                        ? 'per fee line'
                        : number_format((float) $state, 2))
                    ->sortable(),
                TextColumn::make('allocation.feeMaster.feeType.name')
                    ->label(__('Fee head'))
                    ->placeholder(__('All (standing)'))
                    ->toggleable(),
                TextColumn::make('recurrence')
                    ->label(__('Applies'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FeeDiscountStudent::RECURRENCES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === FeeDiscountStudent::RECURRENCE_RECURRING ? 'info' : 'gray'),
                TextColumn::make('approval_status')
                    ->label(__('Approval'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FeeDiscountStudent::APPROVAL_STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => FeeDiscountStudent::APPROVAL_STATUS_COLORS[$state] ?? 'gray'),
                // The one column that answers "has this actually reduced the bill?".
                // A pending concession looks identical to an approved one on every
                // other screen in the product.
                IconColumn::make('applied_at')
                    ->label(__('Off the bill'))
                    ->boolean()
                    ->getStateUsing(fn (FeeDiscountStudent $record): bool => $record->isApplied()),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('source')
                    ->label(__('Basis'))
                    ->formatStateUsing(fn (?string $state): string => FeeDiscountStudent::SOURCES[$state] ?? (string) $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assigner.name')
                    ->label(__('Granted by'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Granted'))
                    ->dateTime('d M Y')
                    ->sortable(),
                TextColumn::make('valid_to')
                    ->label(__('Until'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('approver.name')
                    ->label(__('Approved by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('approval_status')
                    ->label(__('Approval'))
                    ->options(Labels::t(FeeDiscountStudent::APPROVAL_STATUSES)),
                SelectFilter::make('recurrence')
                    ->label(__('Applies'))
                    ->options(Labels::t(FeeDiscountStudent::RECURRENCES)),
                SelectFilter::make('source')
                    ->label(__('Basis'))
                    ->options(Labels::t(FeeDiscountStudent::SOURCES)),
                SelectFilter::make('fee_discount_id')
                    ->label(__('Concession'))
                    ->relationship('discount', 'name'),
                TernaryFilter::make('applied_at')
                    ->label(__('Off the bill'))
                    ->nullable()
                    ->placeholder(__('All'))
                    ->trueLabel(__('Applied'))
                    ->falseLabel(__('Not applied')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (FeeDiscountStudent $record): string => $record->isRecurring()
                        ? __('This concession will come off every fee line raised for this student from now on. Nothing is deducted from an existing line until you use "Apply to open fees".')
                        : __('This reduces what the student owes on that fee line straight away.'))
                    ->visible(fn (FeeDiscountStudent $record): bool => ! $record->isApproved() && static::mayDecide())
                    ->action(function (FeeDiscountStudent $record): void {
                        try {
                            static::decide($record, 'approved', null);
                        } catch (\RuntimeException $e) {
                            // A double-click, or someone else deciding it while
                            // this modal was open. A sentence, not a 500.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Concession approved.'))->send();
                    }),
                Action::make('rejectConcession')
                    ->label(__('Reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (FeeDiscountStudent $record): bool => ! $record->isRejected() && static::mayDecide())
                    ->schema([
                        Textarea::make('rejection_remark')
                            ->label(__('Reason'))
                            ->rows(3)
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->modalDescription(fn (FeeDiscountStudent $record): string => $record->isApplied()
                        ? __('This concession has already come off the bill — rejecting it PUTS THE MONEY BACK and the student owes it again.')
                        : __('Nothing has been deducted for this concession, so no balance changes.'))
                    ->action(function (FeeDiscountStudent $record, array $data): void {
                        try {
                            static::decide($record, 'rejected', (string) $data['rejection_remark']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->warning()->title(__('Concession rejected.'))->send();
                    }),
                // The observer only catches fee lines raised from NOW ON. This is
                // the "…and this term too" click, kept explicit because
                // retro-applying a concession to bills a family may already have
                // been shown is a decision, not a default.
                Action::make('applyToOpenFees')
                    ->label(__('Apply to open fees'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('Raises this standing concession against every fee line this student still owes on. Lines it has already been applied to are skipped.'))
                    ->visible(fn (FeeDiscountStudent $record): bool => $record->isRecurring()
                        && $record->isApproved()
                        && static::mayDecide())
                    ->action(function (FeeDiscountStudent $record): void {
                        $raised = app(ConcessionService::class)->applyStandingToOpenAllocations($record);

                        $raised > 0
                            ? Notification::make()->success()->title(__('Applied to ').$raised.' fee '.($raised === 1 ? 'line' : 'lines').'.')->send()
                            : Notification::make()->warning()->title(__('Nothing to apply — no open fee lines without this concession.'))->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => static::mayDecide())
                    ->modalDescription(fn (FeeDiscountStudent $record): string => $record->isApplied()
                        ? 'Deleting this concession PUTS THE MONEY BACK on the student\'s bill.'
                        : __('Nothing has been deducted for this concession, so no balance changes.')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No concessions granted yet'))
            ->emptyStateDescription(fn (): string => DiscountApprovalPolicy::summary())
            ->defaultSort('created_at', 'desc');
    }
}
