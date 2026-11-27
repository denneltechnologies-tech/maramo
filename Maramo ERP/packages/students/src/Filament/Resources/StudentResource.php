<?php

namespace Classivo\Students\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Core\Models\ClassSection;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Support\Preset;
use Classivo\Students\Filament\Resources\StudentResource\Pages\CreateStudent;
use Classivo\Students\Filament\Resources\StudentResource\Pages\EditStudent;
use Classivo\Students\Filament\Resources\StudentResource\Pages\ListStudents;
use Classivo\Students\Filament\Resources\StudentResource\Pages\ViewStudent;
use Classivo\Students\Models\Attendance;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentCategory;
use Classivo\Students\Services\AdmissionNumberGenerator;
use Classivo\Students\Services\AttendanceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StudentResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'students.students';

    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'first_name';

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-students::students.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-students::students.resources.student');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-students::students.resources.students');
    }

    private static function f(string $key): string
    {
        return __('classivo-students::students.fields.'.$key);
    }

    /** @return array<int, string> */
    public static function classOptions(): array
    {
        return SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public static function sectionOptions(mixed $classId): array
    {
        if (! $classId) {
            return [];
        }

        return ClassSection::query()
            ->where('class_id', $classId)
            ->with('section')
            ->get()
            ->mapWithKeys(fn (ClassSection $cs): array => [$cs->section_id => $cs->section?->name])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => static::f($k);

        return $schema->components([
            Section::make(__('classivo-students::students.sections.academic'))
                ->columns(3)
                ->schema([
                    Select::make('class_id')->label($f('class'))
                        ->options(fn (): array => static::classOptions())
                        ->required()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('section_id', null)),
                    Select::make('section_id')->label($f('section'))
                        ->options(fn (Get $get): array => static::sectionOptions($get('class_id')))
                        ->required(),
                    Select::make('student_category_id')->label($f('category'))
                        ->options(fn (): array => StudentCategory::query()->pluck('name', 'id')->all()),
                    Toggle::make('admission_no_manual')->label($f('admission_no_manual'))
                        ->live()->visibleOn('create')->dehydrated(),
                    TextInput::make('admission_no')->label($f('admission_no'))
                        ->placeholder(fn (): string => app(AdmissionNumberGenerator::class)->peek())
                        ->disabled(fn (Get $get, string $operation): bool => $operation === 'create' && ! $get('admission_no_manual'))
                        ->dehydrated(fn (Get $get, string $operation): bool => $operation !== 'create' || (bool) $get('admission_no_manual'))
                        ->maxLength(50),
                    TextInput::make('roll_no')->label($f('roll_no'))->maxLength(20),
                    DatePicker::make('admission_date')->label($f('admission_date'))->default(now()),
                    DatePicker::make('training_start_date')->label($f('training_start_date')),
                    DatePicker::make('completion_date')->label($f('completion_date')),
                    Select::make('status')->label($f('status'))
                        ->options(fn (): array => (array) __('classivo-students::students.statuses'))
                        ->default('active')->required(),
                ]),
            Section::make(__('classivo-students::students.sections.personal'))
                ->columns(3)
                ->schema([
                    TextInput::make('first_name')->label($f('first_name'))->required()->maxLength(100),
                    TextInput::make('middle_name')->label($f('middle_name'))->maxLength(100),
                    TextInput::make('last_name')->label($f('last_name'))->maxLength(100),
                    Select::make('gender')->label($f('gender'))->options(fn (): array => (array) __('classivo-students::students.genders'))->required(),
                    DatePicker::make('date_of_birth')->label($f('date_of_birth'))->maxDate(now()),
                    TextInput::make('blood_group')->label($f('blood_group'))->maxLength(5),
                    TextInput::make('mobile')->label($f('mobile'))->tel(),
                    TextInput::make('email')->label($f('email'))->email(),
                    TextInput::make('national_id')->label($f('national_id')),
                    TextInput::make('religion')->label($f('religion')),
                    TextInput::make('mother_tongue')->label($f('mother_tongue')),
                ]),
            Section::make(__('classivo-students::students.sections.guardian'))
                ->columns(2)
                ->visibleOn('create')
                ->schema([
                    TextInput::make('father.name')->label($f('father_name')),
                    TextInput::make('father.phone')->label($f('father_phone'))->tel(),
                    TextInput::make('mother.name')->label($f('mother_name')),
                    TextInput::make('mother.phone')->label($f('mother_phone'))->tel(),
                    TextInput::make('guardian.name')->label($f('guardian_name')),
                    Select::make('guardian.relation')->label($f('guardian_relation'))
                        ->options(fn (): array => (array) __('classivo-students::students.relations'))->default('father'),
                    TextInput::make('guardian.phone')->label($f('guardian_phone'))->tel(),
                    TextInput::make('guardian.email')->label($f('guardian_email'))->email(),
                    TextInput::make('emergency_contact_name')->label($f('emergency_contact_name')),
                    TextInput::make('emergency_contact_phone')->label($f('emergency_contact_phone'))->tel(),
                ]),
            Section::make(__('classivo-students::students.sections.address'))
                ->columns(2)
                ->collapsed()
                ->schema([
                    Textarea::make('current_address')->label($f('current_address')),
                    Textarea::make('permanent_address')->label($f('permanent_address')),
                    TextInput::make('city')->label($f('city')),
                    TextInput::make('state')->label($f('state')),
                    TextInput::make('pincode')->label($f('pincode')),
                ]),
            Section::make(__('classivo-students::students.sections.udise'))
                ->columns(3)
                ->collapsed()
                ->visible(fn (): bool => Preset::get('country') === 'IN')
                ->schema([
                    Select::make('social_category')->label($f('social_category'))->options(Student::SOCIAL_CATEGORIES),
                    Toggle::make('rte')->label($f('rte')),
                    Toggle::make('is_bpl')->label($f('is_bpl')),
                    Toggle::make('is_ews')->label($f('is_ews')),
                    Toggle::make('is_cwsn')->label($f('is_cwsn')),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $f = fn (string $k): string => static::f($k);

        return $schema->components([
            Section::make(__('classivo-students::students.sections.academic'))
                ->columns(3)
                ->schema([
                    TextEntry::make('admission_no')->label($f('admission_no')),
                    TextEntry::make('full_name')->label($f('full_name')),
                    TextEntry::make('current_class_section.name')->label($f('class_section'))->placeholder('—'),
                    TextEntry::make('current_roll_no')->label($f('roll_no'))->placeholder('—'),
                    TextEntry::make('admission_date')->label($f('admission_date'))->date(),
                    TextEntry::make('status')->label($f('status'))->badge()
                        ->formatStateUsing(fn (?string $state): string => __('classivo-students::students.statuses.'.$state)),
                ]),
            Section::make(__('classivo-students::students.sections.guardian'))
                ->columns(3)
                ->schema([
                    TextEntry::make('primary_guardian_name')->label($f('guardian_name'))
                        ->state(fn (Student $record): ?string => $record->primaryGuardian()?->name)->placeholder('—'),
                    TextEntry::make('primary_guardian_phone')->label($f('guardian_phone'))
                        ->state(fn (Student $record): ?string => $record->primaryGuardian()?->phone)->placeholder('—'),
                ]),
            Section::make(__('classivo-students::students.sections.attendance'))
                ->columns(4)
                ->schema([
                    TextEntry::make('attendance_percentage')->label($f('percentage'))
                        ->state(fn (Student $record): string => static::monthSummary($record)['percentage'].'%'),
                    ...collect(['present', 'absent', 'late', 'leave'])->map(fn (string $status) => TextEntry::make('attendance_'.$status)
                        ->label(__('classivo-students::students.attendance_statuses.'.$status))
                        ->state(fn (Student $record): int => static::monthSummary($record)['counts'][$status] ?? 0)
                        ->badge()->color(Attendance::STATUS_COLORS[$status]))->all(),
                ]),
        ]);
    }

    /** @return array{counts: array<string,int>, countable: int, present_credit: float, percentage: float} */
    public static function monthSummary(Student $student): array
    {
        return app(AttendanceService::class)->summary(
            $student->id,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => static::f($k);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['classSection.schoolClass', 'classSection.section']))
            ->columns([
                TextColumn::make('admission_no')->label($f('admission_no'))->searchable()->sortable(),
                TextColumn::make('full_name')->label($f('full_name'))
                    ->searchable(['first_name', 'middle_name', 'last_name']),
                TextColumn::make('classSection.name')->label($f('class_section'))->placeholder('—'),
                TextColumn::make('roll_no')->label($f('roll_no'))->placeholder('—'),
                TextColumn::make('gender')->label($f('gender'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __('classivo-students::students.genders.'.$state) : '—'),
                TextColumn::make('mobile')->label($f('mobile'))->placeholder('—'),
                TextColumn::make('status')->label($f('status'))->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'applicant' => 'warning',
                        'completed' => 'info',
                        'withdrawn' => 'gray',
                        'suspended' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __('classivo-students::students.statuses.'.$state) ?? ucfirst($state)),
            ])
            ->filters([
                SelectFilter::make('class_id')->label($f('class'))->options(fn (): array => static::classOptions()),
                SelectFilter::make('status')->label($f('status'))->options(fn (): array => (array) __('classivo-students::students.statuses')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->color('success')
                    ->url(function (Student $record): string {
                        $phone = preg_replace('/[^0-9]/', '', (string) $record->mobile);
                        if (! str_starts_with($phone, '233') && str_starts_with($phone, '0')) {
                            $phone = '233' . substr($phone, 1);
                        }
                        $msg = urlencode("Hello {$record->first_name}, this is Maramo Beauty Institute. Regarding your training program ({$record->schoolClass?->name}): we are checking in with your academic and attendance progress.");
                        return "https://wa.me/{$phone}?text={$msg}";
                    })
                    ->openUrlInNewTab(),
                Action::make('issue_certificate')
                    ->label('Issue Certificate')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('warning')
                    ->visible(fn (Student $record): bool => in_array($record->status, ['completed', 'active'], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Student $record): string => "Issue Certificate for {$record->full_name}")
                    ->modalDescription('This will mint an official Certificate of Professional Competence with an authentic verification QR code and unique serial number.')
                    ->action(function (Student $record) {
                        $school = \Classivo\Core\Models\School::query()->first();
                        $branch = \Classivo\Core\Models\Branch::query()->first();
                        $template = \Classivo\ReportCards\Models\CertificateTemplate::query()->first();

                        $count = \Classivo\ReportCards\Models\GeneratedCertificate::query()->count();
                        $certNo = 'MBI-CERT-' . date('Y') . '-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
                        $verifyUrl = url("/api/verify-certificate/{$certNo}");
                        $qrImg = '<img src="' . \Classivo\ReportCards\Support\QrCode::dataUri($verifyUrl) . '" width="64" height="64" alt="Verification QR" />';

                        $variables = [
                            'student_name' => $record->full_name,
                            'admission_no' => $record->admission_no,
                            'class' => $record->schoolClass?->name ?? 'Professional Beauty Program',
                            'course_name' => $record->schoolClass?->name ?? 'Professional Beauty Program',
                            'date' => now()->format('d F Y'),
                            'certificate_no' => $certNo,
                            'qr_code' => $qrImg,
                            'school_name' => $school?->name ?? 'Maramo Beauty Institute',
                            'branch_name' => $branch?->name ?? 'Accra Campus',
                        ];

                        $html = $template?->content ?? '';
                        $rendered = str_replace(
                            array_map(fn ($k) => '{{' . $k . '}}', array_keys($variables)),
                            array_values($variables),
                            $html
                        );

                        \Classivo\ReportCards\Models\GeneratedCertificate::create([
                            'school_id' => $school?->id ?? 1,
                            'branch_id' => $branch?->id ?? 1,
                            'certificate_template_id' => $template?->id ?? 1,
                            'student_id' => $record->id,
                            'certificate_number' => $certNo,
                            'data' => [
                                'variables' => $variables,
                                'html' => $rendered,
                            ],
                            'issued_by' => auth()->id(),
                            'issued_at' => now(),
                        ]);

                        $record->update(['status' => 'completed']);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Certificate Successfully Issued!')
                            ->body("Certificate {$certNo} generated with QR Code.")
                            ->send();
                    }),
                Action::make('disable')
                    ->label(__('classivo-students::students.actions.disable'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => static::can('update'))
                    ->visible(fn (Student $record): bool => $record->status === 'active')
                    ->action(fn (Student $record) => $record->disableStudent()),
                Action::make('enable')
                    ->label(__('classivo-students::students.actions.enable'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize(fn (): bool => static::can('update'))
                    ->visible(fn (Student $record): bool => $record->status === 'inactive')
                    ->action(fn (Student $record) => $record->reEnable()),
            ])
            ->defaultSort('admission_no');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'view' => ViewStudent::route('/{record}'),
            'edit' => EditStudent::route('/{record}/edit'),
        ];
    }
}
