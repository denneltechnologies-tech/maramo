<?php

namespace Classivo\Exams\Filament\Resources\Grades;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Exams\Filament\Resources\Grades\Pages\CreateGrade;
use Classivo\Exams\Filament\Resources\Grades\Pages\EditGrade;
use Classivo\Exams\Filament\Resources\Grades\Pages\ListGrades;
use Classivo\Exams\Filament\Resources\Grades\Schemas\GradeForm;
use Classivo\Exams\Filament\Resources\Grades\Tables\GradesTable;
use Classivo\Exams\Models\Grade;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * GradeResource. The grading-scale bands
 * used to resolve a student's grade from their percentage. Nav group
 * "Examinations", gated on the (core) exam module.
 */
class GradeResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'exams.grades';

    protected static ?string $model = Grade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.grades_grading_scale');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-exams::ui.grade');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-exams::ui.grades');
    }

    protected static ?int $navigationSort = 14;

    public static function form(Schema $schema): Schema
    {
        return GradeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GradesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGrades::route('/'),
            'create' => CreateGrade::route('/create'),
            'edit' => EditGrade::route('/{record}/edit'),
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
