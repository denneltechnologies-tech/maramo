<?php

namespace Classivo\Exams\Filament\Resources\Exams;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Exams\Filament\Resources\Exams\Pages\CreateExam;
use Classivo\Exams\Filament\Resources\Exams\Pages\EditExam;
use Classivo\Exams\Filament\Resources\Exams\Pages\ListExams;
use Classivo\Exams\Filament\Resources\Exams\Schemas\ExamForm;
use Classivo\Exams\Filament\Resources\Exams\Tables\ExamsTable;
use Classivo\Exams\Models\Exam;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * ExamResource. Concrete exams within an exam group, with a
 * result Publish/Unpublish control (gated by `exam.publish_result`) and shortcuts
 * to the schedule builder, marks register and admit cards. Nav group
 * "Examinations", gated on the (core) exam module.
 */
class ExamResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'exams.exams';

    protected static ?string $model = Exam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.exams');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-exams::ui.exam');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-exams::ui.exams');
    }

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return ExamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExamsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExams::route('/'),
            'create' => CreateExam::route('/create'),
            'edit' => EditExam::route('/{record}/edit'),
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
