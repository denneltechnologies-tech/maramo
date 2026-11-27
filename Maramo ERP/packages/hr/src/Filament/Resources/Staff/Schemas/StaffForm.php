<?php

namespace Classivo\Hr\Filament\Resources\Staff\Schemas;

use Classivo\Hr\Models\Department;
use Classivo\Hr\Models\Designation;
use Classivo\Hr\Models\SalaryComponent;
use Classivo\Hr\Models\Staff;
use Classivo\Hr\Models\StaffSalaryStructure;
use Classivo\Hr\Services\EmployeeIdGenerator;
use Classivo\Hr\Support\Currency;
use Classivo\Hr\Support\HrAuth;
use Classivo\Hr\Support\HrLabels;
use Classivo\Hr\Support\HrPermissions;
use Classivo\Hr\Support\RoleGuard;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Staff create/edit form (spec §15 Staff Directory tabs). Tabs: Personal, Contact,
 * Payroll, Bank, Documents, Login. `employee_id` auto-generates on save when blank
 * (see {@see EmployeeIdGenerator}). The Login tab's enable-login toggle
 * + password are virtual and handled by the Create/Edit pages ({@see Staff::provisionLogin()}).
 */
class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Staff')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('hr::ui.personal'))
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                TextInput::make('employee_id')
                                    ->label(__('hr::ui.staff_id'))
                                    ->placeholder(__('hr::ui.auto_generated_on_save'))
                                    ->helperText(__('hr::ui.leave_blank_to_auto_generate'))
                                    ->disabledOn('edit')
                                    ->maxLength(50),
                                Select::make('role_id')
                                    ->label(__('hr::ui.role'))
                                    ->visible(fn (): bool => HrAuth::roleModel() !== null)
                                    ->disabled(fn (): bool => ! RoleGuard::canAssignRoles())
                                    ->dehydrated(fn (): bool => RoleGuard::canAssignRoles())
                                    ->options(fn (): array => ($roleClass = HrAuth::roleModel())
                                        ? $roleClass::query()->whereIn('id', RoleGuard::grantableRoleIds())->orderBy('name')->pluck('name', 'id')->all()
                                        : [])
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('hr::ui.system_role_assigned_when_login')),
                                Select::make('department_id')
                                    ->label(__('hr::ui.department'))
                                    ->options(fn (): array => Department::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->preload(),
                                Select::make('designation_id')
                                    ->label(__('hr::ui.designation'))
                                    ->options(fn (): array => Designation::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('first_name')->required()->maxLength(100),
                                TextInput::make('last_name')->maxLength(100),
                                TextInput::make('father_name')->maxLength(100),
                                TextInput::make('mother_name')->maxLength(100),
                                Select::make('gender')->options(HrLabels::enum(Staff::GENDERS, 'staff.genders'))->native(false),
                                TextInput::make('blood_group')->maxLength(10),
                                Select::make('marital_status')
                                    ->options(__('hr::enums.staff.marital_statuses'))
                                    ->native(false),
                                DatePicker::make('date_of_birth')->maxDate(now()),
                                DatePicker::make('joining_date')->required()->default(now()),
                                Select::make('status')
                                    ->options(HrLabels::enum(Staff::STATUSES, 'staff.statuses'))
                                    ->default('active')
                                    ->required()
                                    ->native(false),
                                SpatieMediaLibraryFileUpload::make('photo')
                                    ->label(__('hr::ui.photo'))
                                    ->collection('photo')
                                    ->image()
                                    ->avatar()
                                    ->imageEditor()
                                    ->maxSize(2048)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('hr::ui.contact'))
                            ->icon('heroicon-o-phone')
                            ->columns(2)
                            ->schema([
                                TextInput::make('phone')->tel()->maxLength(20),
                                TextInput::make('emergency_contact')->tel()->maxLength(20),
                                TextInput::make('email')->email()->maxLength(255)
                                    ->rules([fn (Get $get, ?Staff $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                                        // A login is only ever created for a NEW account; an email already held by any user is refused.
                                        if ($get('enable_login') && ! $record?->user_id && filled($value)
                                            && HrAuth::userModel()::query()->where('email', $value)->exists()) {
                                            $fail(__('hr::messages.staff.email_in_use'));
                                        }
                                    }]),
                                Textarea::make('current_address')->rows(2)->columnSpanFull(),
                                Textarea::make('permanent_address')->rows(2)->columnSpanFull(),
                                Textarea::make('qualification')->rows(2),
                                Textarea::make('work_experience')->rows(2),
                                Textarea::make('note')->rows(2)->columnSpanFull(),
                                TextInput::make('facebook_url')->label(__('hr::ui.facebook'))->url()->maxLength(255),
                                TextInput::make('twitter_url')->label(__('hr::ui.twitter_x'))->url()->maxLength(255),
                                TextInput::make('linkedin_url')->label(__('hr::ui.linkedin'))->url()->maxLength(255),
                                TextInput::make('instagram_url')->label(__('hr::ui.instagram'))->url()->maxLength(255),
                            ]),

                        Tab::make(__('hr::ui.payroll'))
                            ->icon('heroicon-o-banknotes')
                            ->columns(2)
                            ->schema([
                                // These two were captured as labels and never used
                                // as anything. They are now the MEMBERSHIP TEST the
                                // statutory engine applies: the school's own
                                // statement that this person is a PF / ESI member.
                                TextInput::make('epf_no')
                                    ->label(__('hr::ui.epf_no'))
                                    ->helperText(__('hr::ui.provident_fund_is_deducted_only'))
                                    ->maxLength(50),
                                TextInput::make('esi_no')
                                    ->label(__('hr::ui.esi_no'))
                                    ->helperText(__('hr::ui.esi_is_deducted_only_for'))
                                    ->maxLength(50),
                                TextInput::make('basic_salary')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required()
                                    ->prefix(Currency::symbol())
                                    // ── THIS FIELD HAS NO HISTORY ─────────────
                                    // It is one mutable number, and payroll used
                                    // to read it for EVERY month it priced — so
                                    // typing a raise here restated every payroll
                                    // that was not yet locked, silently, including
                                    // months the employee had already been shown.
                                    // A dated BASIC row on the structure below now
                                    // wins where one exists; this stays as the
                                    // fallback for everybody who has none, which
                                    // is how every school starts.
                                    ->helperText(fn (?Staff $record): string => static::hasBasicStructureRow($record)
                                        ? __('hr::messages.staff.basic_not_used')
                                        : __('hr::messages.staff.basic_fallback')),
                                Select::make('contract_type')
                                    ->options(HrLabels::enum(Staff::CONTRACT_TYPES, 'staff.contract_types'))
                                    ->native(false),
                                TextInput::make('work_shift')->maxLength(50),
                                TextInput::make('work_location')->maxLength(100),
                                TextInput::make('salary_grade')->maxLength(50),
                                // ── THE SALARY STRUCTURE ─────────────────────
                                // Replaces the two free-text repeaters below,
                                // which produced a payroll where "HRA" and
                                // "H.R.A." were different heads and nothing could
                                // be a percentage of anything.
                                //
                                // Rows are EFFECTIVE-DATED, and that is the whole
                                // point: payroll asks what the salary was during
                                // the month it is paying, not what it is today. A
                                // RAISE IS A NEW ROW — close the current one with
                                // an "Effective to" and add another — because
                                // editing the amount in place is what used to let
                                // an issued payslip change retroactively.
                                Repeater::make('salaryStructures')
                                    ->relationship('salaryStructures')
                                    ->label(__('hr::ui.salary_structure'))
                                    ->helperText(__('hr::ui.a_raise_is_a_new'))
                                    ->schema([
                                        Select::make('salary_component_id')
                                            ->label(__('hr::ui.head'))
                                            ->options(fn (): array => SalaryComponent::query()
                                                ->active()
                                                // Heads a person can be put ON.
                                                // BASIC is included DELIBERATELY:
                                                // it is 62% of this payroll and
                                                // leaving it out of the one table
                                                // that has history meant the
                                                // largest head was the only one a
                                                // raise could restate the past
                                                // with. Statutory and system heads
                                                // stay out — they are computed,
                                                // never typed.
                                                ->whereIn('calculation', ['base_salary', 'fixed', 'percentage'])
                                                ->ordered()
                                                ->get()
                                                ->mapWithKeys(fn (SalaryComponent $c): array => [
                                                    $c->id => $c->name.' ('.$c->code.')'
                                                        .($c->isBaseSalary() ? __('hr::messages.staff.dates_basic_change') : ''),
                                                ])
                                                ->all())
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                        TextInput::make('amount')
                                            ->label(__('hr::ui.amount'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->prefix(Currency::symbol())
                                            ->helperText(__('hr::ui.for_a_fixed_head_and')),
                                        TextInput::make('percentage')
                                            ->label(__('hr::ui.rate_override'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->suffix('%')
                                            ->helperText(__('hr::ui.only_for_a_percentage_head')),
                                        DatePicker::make('effective_from')
                                            ->label(__('hr::ui.effective_from'))
                                            ->default(fn (Get $get) => $get('../../joining_date') ?: now()->startOfMonth()->toDateString())
                                            ->required(),
                                        DatePicker::make('effective_to')
                                            ->label(__('hr::ui.effective_to'))
                                            ->helperText(__('hr::ui.blank_while_in_force')),
                                        TextInput::make('note')->maxLength(255),
                                    ])
                                    ->columns(3)
                                    ->itemLabel(fn (array $state): ?string => filled($state['salary_component_id'] ?? null)
                                        ? (SalaryComponent::find($state['salary_component_id'])?->name ?? 'Head')
                                            .' — '.Currency::symbol().number_format((float) ($state['amount'] ?? 0), 2)
                                            .' from '.($state['effective_from'] ?? '?')
                                        : null)
                                    ->collapsed()
                                    ->addActionLabel(__('hr::messages.form.add_salary_head'))
                                    ->defaultItems(0)
                                    // The row's tenant is the EMPLOYEE'S, not the
                                    // operator's. BelongsToTenant/BelongsToBranch
                                    // fill these from the current context, so an
                                    // administrator working with no campus
                                    // selected would have written rows with a null
                                    // branch_id — rows the panel then could not
                                    // see, on a salary that still got paid.
                                    ->mutateRelationshipDataBeforeCreateUsing(function (array $data, ?Staff $record): array {
                                        $data['school_id'] = $record?->school_id ?? ($data['school_id'] ?? null);
                                        $data['branch_id'] = $record?->branch_id ?? ($data['branch_id'] ?? null);

                                        return $data;
                                    })
                                    ->columnSpanFull(),

                                // ── LEGACY, AND READ-ONLY ONCE MIGRATED ──────
                                //
                                // These two repeaters were the ONLY salary editor
                                // this form had before the structure above
                                // existed, and they still LOOK like it. The
                                // helper text used to say payroll reads them
                                // "only when a staff member has no structure
                                // rows", which is not what the engine does: the
                                // merge is per HEAD. Any head that has a
                                // structure row is priced from that row and the
                                // JSON entry beside it is inert — so raising
                                // House Rent Allowance from ₹12,800 to ₹15,000
                                // here saved cleanly, showed the new figure back,
                                // and paid ₹12,800 forever. Deleting a row here
                                // did nothing at all.
                                //
                                // An input that accepts an edit and discards it is
                                // worse than no input, so for anybody who has been
                                // migrated these become read-only and say why.
                                // They stay LIVE and editable for a staff member
                                // with no structure rows, because for that person
                                // the JSON genuinely is their salary and locking
                                // it would leave them uneditable. The transition
                                // between the two states is the "Retire the
                                // free-text salary" action on this page's header,
                                // which is the only safe way to empty them.
                                Text::make(fn (?Staff $record): string => static::legacyStatusLine($record))
                                    ->columnSpanFull(),
                                Repeater::make('allowances')
                                    ->label(__('hr::ui.allowances_legacy_free_text'))
                                    ->helperText(fn (?Staff $record): string => static::legacyHelperText($record, 'allowance'))
                                    ->schema([
                                        TextInput::make('name')->required()->maxLength(100),
                                        TextInput::make('amount')->numeric()->minValue(0)->default(0)->prefix(Currency::symbol()),
                                    ])
                                    ->columns(2)
                                    ->collapsed()
                                    ->addActionLabel(__('hr::messages.form.add_allowance'))
                                    ->default([])
                                    ->disabled(fn (?Staff $record): bool => static::isMigrated($record))
                                    // Belt as well as braces: a disabled field is
                                    // not dehydrated anyway, and saying so here
                                    // means the column cannot be blanked by a
                                    // future change to that default.
                                    ->dehydrated(fn (?Staff $record): bool => ! static::isMigrated($record))
                                    ->addable(fn (?Staff $record): bool => ! static::isMigrated($record))
                                    ->deletable(fn (?Staff $record): bool => ! static::isMigrated($record))
                                    ->columnSpanFull(),
                                Repeater::make('deductions')
                                    ->label(__('hr::ui.deductions_legacy_free_text'))
                                    ->helperText(fn (?Staff $record): string => static::legacyHelperText($record, 'deduction'))
                                    ->schema([
                                        TextInput::make('name')->required()->maxLength(100),
                                        TextInput::make('amount')->numeric()->minValue(0)->default(0)->prefix(Currency::symbol()),
                                    ])
                                    ->columns(2)
                                    ->collapsed()
                                    ->addActionLabel(__('hr::messages.form.add_deduction'))
                                    ->default([])
                                    ->disabled(fn (?Staff $record): bool => static::isMigrated($record))
                                    ->dehydrated(fn (?Staff $record): bool => ! static::isMigrated($record))
                                    ->addable(fn (?Staff $record): bool => ! static::isMigrated($record))
                                    ->deletable(fn (?Staff $record): bool => ! static::isMigrated($record))
                                    ->columnSpanFull(),
                            ]),

                        Tab::make(__('hr::ui.bank'))
                            ->icon('heroicon-o-building-library')
                            ->columns(2)
                            ->schema([
                                TextInput::make('account_title')->maxLength(150),
                                TextInput::make('bank_account_no')->maxLength(50),
                                TextInput::make('bank_name')->maxLength(150),
                                TextInput::make('bank_branch')->maxLength(150),
                                TextInput::make('bank_ifsc')->label(__('hr::ui.ifsc_swift'))->maxLength(50),
                                TextInput::make('tax_id')->label(__('hr::ui.tax_id'))->maxLength(50),
                            ]),

                        Tab::make(__('hr::ui.documents'))
                            ->icon('heroicon-o-paper-clip')
                            ->schema([
                                Repeater::make('documents')
                                    ->relationship('documents')
                                    ->label(__('hr::ui.documents'))
                                    ->schema([
                                        TextInput::make('title')->required()->maxLength(255),
                                        FileUpload::make('file_path')
                                            ->label(__('hr::ui.file'))
                                            ->disk(config('hr.documents_disk', 'local'))
                                            ->visibility('private')
                                            ->directory('staff-documents')
                                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                                            ->rules(['mimes:pdf,jpg,jpeg,png'])
                                            ->openable(false)
                                            ->downloadable(false)
                                            ->previewable(false)
                                            ->maxSize(5120),
                                        // Optional on purpose: most documents never
                                        // lapse (a degree certificate), and an empty
                                        // date means exactly that — not "unknown".
                                        // Filled in, it drives the expiry badge on
                                        // the mobile Documents tab and the staff
                                        // document-expiry alert list.
                                        DatePicker::make('expires_at')
                                            ->label(__('hr::ui.expires_on'))
                                            ->native(false)
                                            ->helperText(__('hr::ui.leave_empty_if_it_does')),
                                        TextInput::make('remark')->maxLength(255),
                                    ])
                                    ->columns(2)
                                    ->addActionLabel(__('hr::messages.form.add_document'))
                                    ->defaultItems(0),
                            ]),

                        // UDISE+ (India) teacher data card.
                        // All fields nullable; non-India schools can leave it empty.
                        Tab::make(__('hr::ui.udise'))
                            ->icon('heroicon-o-flag')
                            ->columns(2)
                            ->schema([
                                Toggle::make('is_teaching_staff')
                                    ->label(__('hr::ui.teaching_staff'))
                                    ->helperText(__('hr::ui.counted_in_the_udise_teacher')),
                                Select::make('academic_qualification')
                                    ->label(__('hr::ui.highest_academic_qualification'))
                                    ->options(HrLabels::enum(Staff::ACADEMIC_QUALIFICATIONS, 'staff.academic_qualifications'))
                                    ->native(false),
                                Select::make('professional_qualification')
                                    ->label(__('hr::ui.professional_qualification'))
                                    ->options(HrLabels::enum(Staff::PROFESSIONAL_QUALIFICATIONS, 'staff.professional_qualifications'))
                                    ->native(false),
                                Select::make('appointment_type')
                                    ->label(__('hr::ui.nature_of_appointment'))
                                    ->options(HrLabels::enum(Staff::APPOINTMENT_TYPES, 'staff.appointment_types'))
                                    ->native(false),
                                TextInput::make('classes_taught')
                                    ->label(__('hr::ui.classes_taught'))
                                    ->placeholder(__('hr::ui.e_g_primary_upper_primary'))
                                    ->maxLength(255),
                                TextInput::make('subjects_taught')
                                    ->label(__('hr::ui.subjects_taught'))
                                    ->maxLength(255),
                                Toggle::make('trained_in_computer')
                                    ->label(__('hr::ui.trained_in_computer')),
                                TextInput::make('in_service_training_days')
                                    ->label(__('hr::ui.in_service_training_days'))
                                    ->numeric()
                                    ->minValue(0),
                                TextInput::make('staff_disability_type')
                                    ->label(__('hr::ui.disability_type'))
                                    ->maxLength(100),
                            ]),

                        Tab::make(__('hr::ui.login'))
                            ->icon('heroicon-o-key')
                            ->columns(2)
                            ->schema([
                                Toggle::make('enable_login')
                                    ->disabled(fn (): bool => ! HrPermissions::can(HrPermissions::STAFF_MANAGE_LOGIN))
                                    ->label(__('hr::ui.enable_login'))
                                    ->helperText(__('hr::ui.creates_links_a_user_account'))
                                    ->dehydrated(false)
                                    ->live()
                                    ->default(false),
                                TextInput::make('login_password')
                                    ->label(__('hr::ui.password'))
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(false)
                                    ->helperText(__('hr::ui.leave_blank_to_default_to'))
                                    ->visible(fn (Get $get): bool => (bool) $get('enable_login')),
                            ]),
                    ]),
            ]);
    }

    // ---------------------------------------------------------------------
    // The legacy / structure seam
    // ---------------------------------------------------------------------

    /**
     * Has this employee been moved onto the salary structure at all?
     *
     * One structure row is enough. From that point every JSON entry beside it is
     * either shadowed by a row (editing it does nothing) or unshadowed and
     * history-less (editing it silently restates months already priced from it).
     * Neither belongs behind an ordinary-looking text box.
     */
    public static function isMigrated(?Staff $record): bool
    {
        return $record?->exists
            && StaffSalaryStructure::query()->withoutGlobalScopes()
                ->where('staff_id', $record->getKey())
                ->exists();
    }

    /** Does a dated BASIC row price this employee's basic pay? */
    public static function hasBasicStructureRow(?Staff $record): bool
    {
        if (! $record?->exists) {
            return false;
        }

        return StaffSalaryStructure::query()->withoutGlobalScopes()
            ->where('staff_id', $record->getKey())
            ->whereHas('component', fn ($q) => $q->withoutGlobalScopes()->where('calculation', 'base_salary'))
            ->exists();
    }

    /** The one-line state of play above the two legacy repeaters. */
    public static function legacyStatusLine(?Staff $record): string
    {
        if (! $record?->exists) {
            return __('hr::messages.staff.legacy_new');
        }

        if (! static::isMigrated($record)) {
            return __('hr::messages.staff.legacy_unmigrated');
        }

        $shadowed = static::shadowedNames($record);
        $live = static::liveLegacyNames($record);

        $parts = [__('hr::messages.staff.legacy_priced')];

        if ($shadowed !== []) {
            $parts[] = trans_choice('hr::messages.staff.legacy_shadowed', count($shadowed), ['count' => count($shadowed), 'names' => implode(', ', $shadowed)]);
        }

        if ($live !== []) {
            $parts[] = trans_choice('hr::messages.staff.legacy_live', count($live), ['count' => count($live), 'names' => implode(', ', $live)]);
        }

        if ($shadowed === [] && $live === []) {
            $parts[] = __('hr::messages.staff.legacy_empty');
        }

        return implode(' ', $parts);
    }

    /** Helper text for one legacy repeater, honest about whether it does anything. */
    public static function legacyHelperText(?Staff $record, string $type): string
    {
        if (! static::isMigrated($record)) {
            return __('hr::messages.staff.helper_live');
        }

        return __('hr::messages.staff.helper_readonly', ['type' => __('hr::messages.staff.type_'.$type)]);
    }

    /**
     * Legacy entries a structure head has already replaced — inert, not paid.
     *
     * Deliberately the SAME question the engine asks, phrased for a human. It is
     * approximate by design (it does not re-price the period); the payroll's own
     * notes are the authoritative answer.
     *
     * @return array<int, string>
     */
    public static function shadowedNames(?Staff $record): array
    {
        return static::splitLegacy($record)['shadowed'];
    }

    /** Legacy entries nothing has replaced — still paid from the free text. */
    public static function liveLegacyNames(?Staff $record): array
    {
        return static::splitLegacy($record)['live'];
    }

    /**
     * Split the two JSON columns into "already replaced" and "still paid".
     *
     * @return array{shadowed: array<int, string>, live: array<int, string>}
     */
    protected static function splitLegacy(?Staff $record): array
    {
        if (! $record?->exists) {
            return ['shadowed' => [], 'live' => []];
        }

        $covered = [];

        $rows = StaffSalaryStructure::query()->withoutGlobalScopes()
            ->where('staff_id', $record->getKey())
            ->with(['component' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($rows as $row) {
            $component = $row->component;

            if ($component === null) {
                continue;
            }

            foreach ([$row->legacy_name, $component->name] as $name) {
                $name = trim((string) $name);

                if ($name !== '') {
                    $covered[$component->type.'|'.strtolower($name)] = true;
                    $covered[$component->type.'#'.SalaryComponent::codeFor($name)] = true;
                }
            }

            $covered[$component->type.'#'.$component->code] = true;
        }

        $shadowed = [];
        $live = [];

        foreach (['allowance' => $record->allowances, 'deduction' => $record->deductions] as $type => $raw) {
            foreach ((array) ($raw ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));
                $amount = (float) ($entry['amount'] ?? 0);

                if ($name === '' || $amount == 0.0) {
                    continue;
                }

                $isCovered = isset($covered[$type.'|'.strtolower($name)])
                    || isset($covered[$type.'#'.SalaryComponent::codeFor($name)]);

                if ($isCovered) {
                    $shadowed[] = $name;
                } else {
                    $live[] = $name;
                }
            }
        }

        return ['shadowed' => $shadowed, 'live' => $live];
    }
}
