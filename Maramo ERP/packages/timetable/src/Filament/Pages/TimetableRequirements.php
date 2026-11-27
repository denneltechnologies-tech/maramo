<?php

namespace Classivo\Timetable\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\ClassSubject;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Core\Models\Subject;
use Classivo\Timetable\Models\Room;
use Classivo\Timetable\Models\SubjectRequirement;
use Classivo\Timetable\Support\Authorize;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Timetable Requirements — what the generator must schedule.
 *
 * Pick a class (and optionally one section; blank = every section) to edit,
 * per allotted subject, the periods a week and the room type it needs. Below,
 * manage the shared rooms (name + type) the generator books for those types.
 * Gated by the `edit` ability (timetable.edit).
 *
 * @property-read Schema $form
 */
class TimetableRequirements extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Authorize::can('edit');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-timetable::timetable.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('classivo-timetable::timetable.requirements.title');
    }

    public function getTitle(): string
    {
        return __('classivo-timetable::timetable.requirements.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'class_id' => null,
            'section_id' => null,
            'subjects' => [],
            'rooms' => $this->existingRooms(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __('classivo-timetable::timetable.'.$key);

        return $schema
            ->components([
                FormSection::make($t('requirements.subjects_section'))
                    ->description($t('requirements.subjects_help'))
                    ->columns(2)
                    ->schema([
                        Select::make('class_id')
                            ->label($t('form.class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                $set('section_id', null);
                                $set('subjects', $this->subjectRows($state ? (int) $state : null, null));
                            }),
                        Select::make('section_id')
                            ->label($t('form.section'))
                            ->placeholder($t('requirements.all_sections'))
                            ->options(fn (Get $get): array => $get('class_id')
                                ? Section::query()->whereHas('classSections', fn ($q) => $q->where('class_id', $get('class_id')))->orderBy('name')->pluck('name', 'id')->all()
                                : [])
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                $classId = $get('class_id') ? (int) $get('class_id') : null;
                                $set('subjects', $this->subjectRows($classId, $state ? (int) $state : null));
                            }),
                        Repeater::make('subjects')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->columns(3)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->schema([
                                Hidden::make('subject_id'),
                                TextInput::make('subject_name')->label($t('form.subject'))->disabled()->dehydrated(false),
                                TextInput::make('weekly_periods')->label($t('requirements.weekly_periods'))->numeric()->minValue(0)->maxValue(60)->required(),
                                TextInput::make('room_type')->label($t('requirements.room_type'))->maxLength(50)->placeholder($t('requirements.own_classroom')),
                            ]),
                    ]),

                FormSection::make($t('requirements.rooms_section'))
                    ->description($t('requirements.rooms_help'))
                    ->schema([
                        Repeater::make('rooms')
                            ->hiddenLabel()
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel($t('requirements.add_room'))
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')->label($t('requirements.room_name'))->required()->maxLength(50),
                                TextInput::make('type')->label($t('requirements.room_type'))->required()->maxLength(50),
                                Toggle::make('status')->label($t('requirements.active'))->default(true)->inline(false),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! Authorize::can('edit')) {
            abort(403);
        }

        $data = $this->form->getState();
        $classId = $data['class_id'] ?? null;
        $sectionId = $data['section_id'] ?? null;

        DB::transaction(function () use ($data, $classId, $sectionId): void {
            if ($classId) {
                foreach ($data['subjects'] ?? [] as $row) {
                    if (empty($row['subject_id'])) {
                        continue;
                    }
                    SubjectRequirement::updateOrCreate(
                        ['class_id' => (int) $classId, 'section_id' => $sectionId ? (int) $sectionId : null, 'subject_id' => (int) $row['subject_id']],
                        ['weekly_periods' => (int) $row['weekly_periods'], 'room_type' => filled($row['room_type'] ?? null) ? trim($row['room_type']) : null],
                    );
                }
            }

            $keep = [];
            foreach ($data['rooms'] ?? [] as $row) {
                $room = ! empty($row['id']) ? Room::find($row['id']) : null;
                $room ??= new Room;
                $room->fill(['name' => trim($row['name']), 'type' => trim($row['type']), 'status' => (bool) ($row['status'] ?? true)])->save();
                $keep[] = $room->id;
            }
            Room::query()->whereNotIn('id', $keep ?: [0])->delete();
        });

        $this->data['rooms'] = $this->existingRooms();

        Notification::make()->title(__('classivo-timetable::timetable.requirements.saved'))->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('classivo-timetable::timetable.form.save'))
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    /**
     * One row per subject allotted to the class (or section), pre-filled from
     * the stored requirement: exact row, else the class-wide row, else config.
     *
     * @return list<array<string, mixed>>
     */
    protected function subjectRows(?int $classId, ?int $sectionId): array
    {
        if (! $classId) {
            return [];
        }

        $subjectIds = ClassSubject::query()
            ->where('class_id', $classId)
            ->where(fn ($q) => $q->whereNull('section_id')->when($sectionId, fn ($q) => $q->orWhere('section_id', $sectionId)))
            ->orderBy('sort_order')
            ->pluck('subject_id')
            ->unique()
            ->values();

        $names = Subject::query()->whereKey($subjectIds)->pluck('name', 'id');
        $stored = SubjectRequirement::query()->where('class_id', $classId)->get();
        $default = (int) config('classivo-timetable.generator.default_weekly_periods', 5);

        return $subjectIds->map(function (int $subjectId) use ($names, $stored, $sectionId, $default): array {
            $row = ($sectionId ? $stored->first(fn ($r) => $r->section_id === $sectionId && $r->subject_id === $subjectId) : null)
                ?? $stored->first(fn ($r) => $r->section_id === null && $r->subject_id === $subjectId);

            return [
                'subject_id' => $subjectId,
                'subject_name' => $names[$subjectId] ?? (string) $subjectId,
                'weekly_periods' => $row?->weekly_periods ?? $default,
                'room_type' => $row?->room_type,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    protected function existingRooms(): array
    {
        return Room::query()->orderBy('name')->get()
            ->map(fn (Room $room): array => ['id' => $room->id, 'name' => $room->name, 'type' => $room->type, 'status' => $room->status])
            ->all();
    }
}
