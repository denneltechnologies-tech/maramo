<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscountStudents;

use BackedEnum;
use Classivo\Fees\Filament\Concerns\AuthorizesWithFeesPermission;
use Classivo\Fees\Filament\Resources\FeeDiscountStudents\Pages\CreateFeeDiscountStudent;
use Classivo\Fees\Filament\Resources\FeeDiscountStudents\Pages\ListFeeDiscountStudents;
use Classivo\Fees\Filament\Resources\FeeDiscountStudents\Schemas\FeeDiscountStudentForm;
use Classivo\Fees\Filament\Resources\FeeDiscountStudents\Tables\FeeDiscountStudentsTable;
use Classivo\Fees\Models\FeeDiscountStudent;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Concession Register. Nav group "Fees Collection".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE SCREEN `fee_discount_students` NEVER HAD
 * ─────────────────────────────────────────────────────────────────────────────
 * The table has existed since the fees module was built and had NO user interface
 * whatsoever. One writer populated it — the admission wizard — nothing read it
 * except a count badge on the discounts list, and a school therefore had no way at
 * all to answer "who holds a concession here?". The discount masters screen shows
 * how many students hold each concession; it cannot show WHICH, when it was
 * granted, by whom, or why.
 *
 * This resource is that answer, and it is also the approval workflow: the Approve
 * and Reject actions call {@see FeeDiscountStudent::approve()} / ::reject(), the
 * only model transitions that move a student's balance for a concession.
 *
 * EDITING IS NOT OFFERED. A granted concession is an audited money decision; the
 * ways to change one are to approve it, reject it with a reason, or delete it —
 * and deleting reverses the money, because {@see FeeDiscountStudent::booted()}
 * makes it. An in-place edit of `amount` would move a student's balance with
 * nothing but an activity-log line to say so.
 */
class FeeDiscountStudentResource extends Resource
{
    use AuthorizesWithFeesPermission;

    public static function viewPermission(): string
    {
        return FeesPermissions::DISCOUNT;
    }

    public static function managePermission(): string
    {
        return FeesPermissions::DISCOUNT;
    }

    protected static ?string $model = FeeDiscountStudent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-fees::fees.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('Concession Register');
    }

    public static function getModelLabel(): string
    {
        return __('Concession');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Concessions');
    }

    protected static ?int $navigationSort = 45;

    /**
     * ACCESS IS THE MODULE GATE, EXACTLY AS ON THE DISCOUNT MASTERS SCREEN — and
     * `fees.discount` gates every ACTION on it (grant, approve, reject, delete),
     * not the reading of it.
     *
     * That split is deliberate. Seeing which families hold a concession is what a
     * head of school or a class teacher legitimately needs; GRANTING one is the
     * money privilege, and it is checked on each action in
     * {@see FeeDiscountStudentsTable} and
     * {@see CreateFeeDiscountStudent}. Narrowing the whole resource instead
     * would also have made this the only Fees resource with a second gate on
     * viewing, which is the kind of inconsistency that gets "fixed" later by
     * someone removing it.
     */
    public static function form(Schema $schema): Schema
    {
        return FeeDiscountStudentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeDiscountStudentsTable::configure($table);
    }

    /** The count that matters on a register is what is WAITING, not what exists. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->pendingApproval()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeDiscountStudents::route('/'),
            'create' => CreateFeeDiscountStudent::route('/create'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
