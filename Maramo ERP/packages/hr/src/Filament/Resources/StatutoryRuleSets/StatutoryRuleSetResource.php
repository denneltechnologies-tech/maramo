<?php

namespace Classivo\Hr\Filament\Resources\StatutoryRuleSets;

use BackedEnum;
use Classivo\Hr\Filament\Concerns\HrNavigation;
use Classivo\Hr\Filament\Resources\BaseResource as Resource;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\Pages\CreateStatutoryRuleSet;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\Pages\EditStatutoryRuleSet;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\Pages\ListStatutoryRuleSets;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\Schemas\StatutoryRuleSetForm;
use Classivo\Hr\Filament\Resources\StatutoryRuleSets\Tables\StatutoryRuleSetsTable;
use Classivo\Hr\Models\StatutoryRuleSet;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * StatutoryRuleSetResource (spec §15 Payroll "Statutory deductions") — where PF
 * rates, the ESI threshold, professional-tax slabs and income-tax slabs are
 * ENTERED, per financial year and per state.
 *
 * This screen is the reason none of those figures is a constant in a service
 * class: a school in a different state, or the same school next April, must be
 * able to be paid correctly without a deploy. Nav group "Human Resource"; gated
 * on the HR module and on `payroll.generate`, since a number typed here changes
 * what every employee takes home.
 */
class StatutoryRuleSetResource extends Resource
{
    use HrNavigation;

    protected static string $navKey = 'statutory_rules';

    protected static string $permissionPrefix = 'hr.statutory_rules';

    protected static ?string $model = StatutoryRuleSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 81;

    public static function form(Schema $schema): Schema
    {
        return StatutoryRuleSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StatutoryRuleSetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatutoryRuleSets::route('/'),
            'create' => CreateStatutoryRuleSet::route('/create'),
            'edit' => EditStatutoryRuleSet::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
