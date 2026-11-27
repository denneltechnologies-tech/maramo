<?php

namespace App\Filament\Resources;

use App\Models\CourseApplication;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
use Classivo\Students\Models\Student;
use Classivo\Students\Models\StudentCategory;
use Classivo\Students\Services\StudentAdmissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseApplicationResource extends Resource
{
    protected static ?string $model = CourseApplication::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static \UnitEnum|string|null $navigationGroup = 'Admissions & CRM';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Admissions Pipeline';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make('Applicant Information')
                ->columns(3)
                ->schema([
                    TextInput::make('application_no')
                        ->label('Application No.')
                        ->default(fn () => CourseApplication::generateApplicationNumber())
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('first_name')->label('First Name')->required(),
                    TextInput::make('last_name')->label('Last Name')->required(),
                    TextInput::make('phone')->label('Phone / WhatsApp')->tel()->required(),
                    TextInput::make('email')->label('Email Address')->email(),
                    Select::make('gender')
                        ->label('Gender')
                        ->options(['female' => 'Female', 'male' => 'Male', 'other' => 'Other'])
                        ->default('female'),
                    DatePicker::make('date_of_birth')->label('Date of Birth'),
                    TextInput::make('city')->label('City / Town')->default('Accra'),
                    TextInput::make('address')->label('Residential Address')->columnSpan(2),
                ]),

            FormSection::make('Program & Schedule Selection')
                ->columns(3)
                ->schema([
                    Select::make('class_id')
                        ->label('Course / Training Program')
                        ->options(fn () => SchoolClass::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('session_id')
                        ->label('Intake / Cohort')
                        ->options(fn () => AcademicSession::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('preferred_schedule')
                        ->label('Preferred Schedule')
                        ->options(CourseApplication::SCHEDULES)
                        ->default('morning'),
                    Select::make('source')
                        ->label('Application Source')
                        ->options(CourseApplication::SOURCES)
                        ->default('walk_in'),
                    Textarea::make('prior_experience')
                        ->label('Prior Beauty Experience')
                        ->placeholder('e.g., beginner, self-taught, worked in salon')
                        ->columnSpan(2),
                ]),

            FormSection::make('Review & Decision')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label('Application Status')
                        ->options(CourseApplication::STATUSES)
                        ->default('pending')
                        ->required(),
                    Textarea::make('notes')
                        ->label('Internal Review Notes')
                        ->placeholder('Add comments, interview feedback, or special instructions'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('application_no')
                    ->label('Ref No.')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    ->description(fn (CourseApplication $record) => $record->created_at?->format('M d, Y')),

                TextColumn::make('full_name')
                    ->label('Applicant')
                    ->searchable(['first_name', 'last_name'])
                    ->weight('bold')
                    ->description(fn (CourseApplication $record) => $record->phone . ($record->city ? ' • ' . $record->city : '')),

                TextColumn::make('course.name')
                    ->label('Program & Schedule')
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (CourseApplication $record) => (CourseApplication::SCHEDULES[$record->preferred_schedule] ?? ucfirst($record->preferred_schedule ?? '')) . ($record->intake ? ' (' . $record->intake->name . ')' : '')),

                TextColumn::make('intake.name')
                    ->label('Intake Cohort')
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('source')
                    ->label('Channel')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'website' => 'info',
                        'walk_in' => 'success',
                        'referral' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => CourseApplication::SOURCES[$state] ?? ucfirst($state)),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'under_review' => 'info',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'enrolled' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => CourseApplication::STATUSES[$state] ?? ucfirst($state)),
            ])
            ->filters([
                SelectFilter::make('status')->options(CourseApplication::STATUSES),
                SelectFilter::make('class_id')->label('Program')->options(fn () => SchoolClass::query()->pluck('name', 'id')->all()),
                SelectFilter::make('session_id')->label('Intake')->options(fn () => AcademicSession::query()->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (CourseApplication $record): bool => in_array($record->status, ['pending', 'under_review']))
                    ->requiresConfirmation()
                    ->action(function (CourseApplication $record) {
                        $record->update([
                            'status' => 'approved',
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);
                        Notification::make()->success()->title('Application approved')->send();
                    }),

                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->color('success')
                    ->url(function (CourseApplication $record): string {
                        $phone = preg_replace('/[^0-9]/', '', (string) $record->phone);
                        if (! str_starts_with($phone, '233') && str_starts_with($phone, '0')) {
                            $phone = '233' . substr($phone, 1);
                        }
                        $msg = urlencode("Hello {$record->first_name}, this is Maramo Beauty Institute. Regarding your application {$record->application_no} for {$record->course?->name}: we have received your application and would like to invite you for your program orientation.");
                        return "https://wa.me/{$phone}?text={$msg}";
                    })
                    ->openUrlInNewTab(),

                Action::make('admit_student')
                    ->label('Admit Student')
                    ->icon(Heroicon::OutlinedAcademicCap)
                    ->color('primary')
                    ->visible(fn (CourseApplication $record): bool => $record->status === 'approved' && ! $record->student_id)
                    ->requiresConfirmation()
                    ->modalHeading('Admit Applicant as Official Student')
                    ->modalDescription('This will create an official Student profile, assign an Admission Number, and link this applicant to the selected Course.')
                    ->action(function (CourseApplication $record) {
                        $categoryId = StudentCategory::query()->where('name', 'Regular Trainee')->value('id')
                            ?? StudentCategory::query()->value('id');

                        $section = Section::query()->first();

                        $admission = app(StudentAdmissionService::class);
                        $student = $admission->admit([
                            'class_id' => $record->class_id,
                            'section_id' => $section?->id,
                            'student_category_id' => $categoryId,
                            'first_name' => $record->first_name,
                            'last_name' => $record->last_name,
                            'gender' => $record->gender ?? 'female',
                            'date_of_birth' => $record->date_of_birth?->toDateString(),
                            'mobile' => $record->phone,
                            'email' => $record->email,
                            'current_address' => $record->address,
                            'city' => $record->city ?? 'Accra',
                            'training_start_date' => now()->toDateString(),
                            'status' => 'active',
                        ]);

                        $record->update([
                            'status' => 'enrolled',
                            'student_id' => $student->id,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Student Successfully Enrolled!')
                            ->body("Admitted as {$student->full_name} (ID: {$student->admission_no})")
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\CourseApplicationResource\Pages\ListCourseApplications::route('/'),
            'create' => \App\Filament\Resources\CourseApplicationResource\Pages\CreateCourseApplication::route('/create'),
            'edit' => \App\Filament\Resources\CourseApplicationResource\Pages\EditCourseApplication::route('/{record}/edit'),
        ];
    }
}
