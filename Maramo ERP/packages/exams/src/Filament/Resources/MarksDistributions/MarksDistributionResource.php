<?php

namespace Classivo\Exams\Filament\Resources\MarksDistributions;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Exams\Filament\Resources\MarksDistributions\Pages\CreateMarksDistribution;
use Classivo\Exams\Filament\Resources\MarksDistributions\Pages\EditMarksDistribution;
use Classivo\Exams\Filament\Resources\MarksDistributions\Pages\ListMarksDistributions;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Exams\Models\MarksDistribution;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * MarksDistributionResource. Optional per-schedule
 * assessment components (Theory / Practical / Assignment …). Light CRUD in Phase 3B
 * — not yet wired into the single-figure marks register. Nav group "Examinations".
 */
class MarksDistributionResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'exams.marks_distributions';

    protected static ?string $model = MarksDistribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.marks_distribution');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-exams::ui.marks_distribution');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-exams::ui.marks_distributions');
    }

    protected static ?int $navigationSort = 19;

    protected static function scheduleOptions(): array
    {
        return ExamSchedule::query()
            ->with(['exam', 'schoolClass', 'section', 'subject'])
            ->get()
            ->mapWithKeys(fn (ExamSchedule $s): array => [$s->id => $s->label])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('classivo-exams::ui.component'))
                ->columns(2)
                ->schema([
                    Select::make('exam_schedule_id')
                        ->label(__('classivo-exams::ui.exam_schedule'))
                        ->options(fn (): array => self::scheduleOptions())
                        ->searchable()
                        ->required(),
                    TextInput::make('name')->label(__('classivo-exams::ui.component'))->placeholder(__('classivo-exams::ui.theory_practical'))->required()->maxLength(100),
                    TextInput::make('full_marks')->label(__('classivo-exams::ui.full_marks'))->numeric()->required()->minValue(0),
                    TextInput::make('pass_marks')->label(__('classivo-exams::ui.pass_marks'))->numeric()->minValue(0),
                    TextInput::make('weightage')->label(__('classivo-exams::ui.weightage'))->numeric()->minValue(0)->maxValue(100),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['examSchedule.exam', 'examSchedule.subject', 'examSchedule.schoolClass']))
            ->columns([
                TextColumn::make('examSchedule.label')->label(__('classivo-exams::ui.schedule'))->wrap()->searchable(false),
                TextColumn::make('name')->label(__('classivo-exams::ui.component'))->weight('medium'),
                TextColumn::make('full_marks')->label(__('classivo-exams::ui.full')),
                TextColumn::make('pass_marks')->label(__('classivo-exams::ui.pass'))->placeholder('—'),
                TextColumn::make('weightage')->label(__('classivo-exams::ui.weight'))->placeholder('—'),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarksDistributions::route('/'),
            'create' => CreateMarksDistribution::route('/create'),
            'edit' => EditMarksDistribution::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
