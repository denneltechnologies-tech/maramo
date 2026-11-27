<?php

namespace Classivo\Exams\Filament\Resources\ExamGroups;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Exams\Filament\Resources\ExamGroups\Pages\CreateExamGroup;
use Classivo\Exams\Filament\Resources\ExamGroups\Pages\EditExamGroup;
use Classivo\Exams\Filament\Resources\ExamGroups\Pages\ListExamGroups;
use Classivo\Exams\Filament\Resources\ExamGroups\Schemas\ExamGroupForm;
use Classivo\Exams\Filament\Resources\ExamGroups\Tables\ExamGroupsTable;
use Classivo\Exams\Models\ExamGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * ExamGroupResource. Groups of exams with an
 * assessment type (Marks / GPA / Both). Nav group "Examinations", gated on the
 * (core) exam module.
 */
class ExamGroupResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'exams.groups';

    protected static ?string $model = ExamGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.exam_group_type');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-exams::ui.exam_group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-exams::ui.exam_groups');
    }

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ExamGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExamGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExamGroups::route('/'),
            'create' => CreateExamGroup::route('/create'),
            'edit' => EditExamGroup::route('/{record}/edit'),
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
