<?php

namespace Classivo\Students\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\Subject;
use Classivo\Students\Models\SubjectAttendance;
use Classivo\Students\Services\AttendanceService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Subject / period-wise attendance. Same flow as {@see TakeAttendance} with a
 * subject (and optional period) selector.
 */
class TakeSubjectAttendance extends TakeAttendance
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'take-subject-attendance';

    public static function getNavigationLabel(): string
    {
        return __('classivo-students::students.pages.subject_attendance');
    }

    public function getTitle(): string
    {
        return __('classivo-students::students.pages.subject_attendance');
    }

    protected function extraSelectors(): array
    {
        return [
            Select::make('subject_id')
                ->label(__('classivo-students::students.fields.subject'))
                ->options(fn (Get $get): array => static::subjectOptions($get('class_section_id')))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('students', [])),
            TextInput::make('period_no')
                ->label(__('classivo-students::students.fields.period'))
                ->numeric()->minValue(1)->maxValue(12),
        ];
    }

    /** Subjects allotted to the class (falls back to every subject). */
    public static function subjectOptions(mixed $classSectionId): array
    {
        $classSection = $classSectionId ? ClassSection::find($classSectionId) : null;

        $allotted = $classSection
            ? ClassSubject::query()->where('class_id', $classSection->class_id)->pluck('subject_id')->all()
            : [];

        return Subject::query()
            ->when($allotted !== [], fn ($q) => $q->whereIn('id', $allotted))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function existingMarks(ClassSection $classSection, array $data): Collection
    {
        $period = filled($data['period_no'] ?? null) ? (int) $data['period_no'] : null;

        return SubjectAttendance::query()
            ->where('class_section_id', $classSection->id)
            ->where('subject_id', (int) ($data['subject_id'] ?? 0))
            ->whereDate('date', $data['date'])
            ->where(fn ($q) => $period === null ? $q->whereNull('period_no') : $q->where('period_no', $period))
            ->get()
            ->keyBy('student_id');
    }

    protected function persist(ClassSection $classSection, array $data, array $rows): int
    {
        return app(AttendanceService::class)->saveSubject(
            $classSection,
            (int) $data['subject_id'],
            $data['date'],
            filled($data['period_no'] ?? null) ? (int) $data['period_no'] : null,
            $rows,
            auth()->id(),
        );
    }
}
