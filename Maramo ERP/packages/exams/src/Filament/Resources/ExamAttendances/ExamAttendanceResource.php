<?php

namespace Classivo\Exams\Filament\Resources\ExamAttendances;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Exams\Filament\Resources\ExamAttendances\Pages\CreateExamAttendance;
use Classivo\Exams\Filament\Resources\ExamAttendances\Pages\EditExamAttendance;
use Classivo\Exams\Filament\Resources\ExamAttendances\Pages\ListExamAttendances;
use Classivo\Exams\Models\ExamAttendance;
use Classivo\Exams\Models\ExamSchedule;
use Classivo\Students\Models\Student;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * ExamAttendanceResource. Present/absent per exam-
 * schedule sitting. Light CRUD in Phase 3B (noted as lighter depth). Nav group
 * "Examinations".
 */
class ExamAttendanceResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'exams.attendances';

    protected static ?string $model = ExamAttendance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-exams::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-exams::ui.exam_attendance');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-exams::ui.exam_attendance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-exams::ui.exam_attendance');
    }

    protected static ?int $navigationSort = 17;

    protected static function scheduleOptions(): array
    {
        return ExamSchedule::query()
            ->with(['exam', 'schoolClass', 'section', 'subject'])
            ->get()
            ->mapWithKeys(fn (ExamSchedule $s): array => [$s->id => $s->label])
            ->all();
    }

    protected static function studentOptions(): array
    {
        return Student::query()
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Student $s): array => [$s->id => $s->full_name.' ('.$s->admission_no.')'])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('classivo-exams::ui.exam_attendance'))
                ->columns(2)
                ->schema([
                    Select::make('exam_schedule_id')
                        ->label(__('classivo-exams::ui.exam_schedule'))
                        ->options(fn (): array => self::scheduleOptions())
                        ->searchable()
                        ->required(),
                    Select::make('student_id')
                        ->label(__('classivo-exams::ui.student'))
                        ->options(fn (): array => self::studentOptions())
                        ->searchable()
                        ->required(),
                    Toggle::make('present')->label(__('classivo-exams::ui.present'))->default(true),
                    TextInput::make('remark')->maxLength(150),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['examSchedule.exam', 'examSchedule.subject', 'student']))
            ->columns([
                TextColumn::make('examSchedule.label')->label(__('classivo-exams::ui.schedule'))->wrap(),
                TextColumn::make('student.full_name')->label(__('classivo-exams::ui.student'))->searchable(['first_name', 'last_name']),
                IconColumn::make('present')->label(__('classivo-exams::ui.field_present'))->boolean(),
                TextColumn::make('remark')->label(__('classivo-exams::ui.field_remark'))->placeholder('—')->toggleable(),
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
            'index' => ListExamAttendances::route('/'),
            'create' => CreateExamAttendance::route('/create'),
            'edit' => EditExamAttendance::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
