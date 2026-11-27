<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Models\FeeType;
use Classivo\Fees\Services\Fees\FeeDefaulterQuery;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Students\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Search Due Fees — every student with an outstanding balance, filterable by
 * class, session (arrears from past sessions included), fee head, days overdue
 * and minimum amount, with a link straight to the counter.
 */
class SearchDueFees extends FeesPage implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 48;

    protected string $view = 'classivo-fees::filament.pages.search-due-fees';

    public ?array $data = [];

    protected ?Collection $rowCache = null;

    public static function permission(): string
    {
        return FeesPermissions::DUES_VIEW;
    }

    protected static function englishTitle(): string
    {
        return 'Search Due Fees';
    }

    public function mount(): void
    {
        $this->form->fill([
            'class_id' => null,
            'session_id' => current_session_id(),
            'fee_type_id' => null,
            'as_of' => now()->toDateString(),
            'min_days_overdue' => null,
            'min_due' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('Filters'))
                    ->columns(3)
                    ->schema([
                        Select::make('class_id')
                            ->label(__('Class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),
                        Select::make('session_id')
                            ->label(__('Academic session'))
                            ->options(fn (): array => AcademicSession::query()->orderByDesc('id')->pluck('name', 'id')->all())
                            ->live(),
                        Select::make('fee_type_id')
                            ->label(__('Fee head'))
                            ->options(fn (): array => FeeType::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),
                        DatePicker::make('as_of')->label(__('Overdue as of'))->native(false)->live(),
                        TextInput::make('min_days_overdue')->label(__('Minimum days overdue'))->numeric()->minValue(0)->live(),
                        TextInput::make('min_due')->label(__('Minimum due amount'))->numeric()->minValue(0)->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function updatedData(): void
    {
        $this->rowCache = null;
    }

    /** @return Collection<int, object> */
    public function dueRows(): Collection
    {
        if ($this->rowCache !== null) {
            return $this->rowCache;
        }

        $balance = app(FeeDefaulterQuery::class)->balanceExpression();
        $asOf = Carbon::parse((string) ($this->data['as_of'] ?? now()->toDateString()))->toDateString();
        $minDue = max(0.01, (float) ($this->data['min_due'] ?? 0));
        $minDays = max(0, (int) ($this->data['min_days_overdue'] ?? 0));

        $query = FeeStudentAllocation::query()
            ->when($this->data['session_id'] ?? null, fn (Builder $q, $v) => $q
                ->withoutGlobalScope(SessionScope::class)
                ->where('fee_student_allocations.session_id', (int) $v))
            ->join('students', 'students.id', '=', 'fee_student_allocations.student_id')
            ->whereNull('students.deleted_at')
            ->whereRaw("{$balance} > 0")
            ->when($this->data['class_id'] ?? null, fn (Builder $q, $v) => $q->where('students.class_id', (int) $v))
            ->when($this->data['fee_type_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('feeMaster', fn (Builder $m) => $m->where('fee_type_id', (int) $v)))
            ->toBase()
            ->select('fee_student_allocations.student_id')
            ->selectRaw("SUM({$balance}) AS due")
            ->selectRaw("SUM(CASE WHEN fee_student_allocations.due_date IS NOT NULL AND fee_student_allocations.due_date < ? THEN {$balance} ELSE 0 END) AS overdue", [$asOf])
            ->groupBy('fee_student_allocations.student_id')
            // Inlined as a numeric literal: SQLite compares a bound string as text.
            ->havingRaw("SUM({$balance}) >= ".sprintf('%.2F', $minDue))
            ->orderByRaw("SUM({$balance}) DESC");

        if ($minDays > 0) {
            $query->havingRaw(
                "SUM(CASE WHEN fee_student_allocations.due_date IS NOT NULL AND fee_student_allocations.due_date <= ? THEN {$balance} ELSE 0 END) > 0",
                [Carbon::parse($asOf)->subDays($minDays)->toDateString()],
            );
        }

        $aggregates = $query->get();

        if ($aggregates->isEmpty()) {
            return $this->rowCache = collect();
        }

        $students = Student::query()
            ->whereIn('id', $aggregates->pluck('student_id')->all())
            ->with(['schoolClass', 'guardians'])
            ->get()
            ->keyBy('id');

        return $this->rowCache = $aggregates->map(function (object $row) use ($students): object {
            $student = $students->get((int) $row->student_id);
            $guardian = $student?->primaryGuardian();

            return (object) [
                'student_id' => (int) $row->student_id,
                'name' => $student?->full_name ?? '—',
                'admission_no' => $student?->admission_no ?? '—',
                'class' => $student?->schoolClass?->name ?? '—',
                'guardian' => $guardian?->name ?? '—',
                'phone' => $guardian?->phone ?: ($student?->mobile ?: '—'),
                'due' => round((float) $row->due, 2),
                'overdue' => round((float) $row->overdue, 2),
            ];
        })->values();
    }

    public function grandTotalDue(): float
    {
        return round((float) $this->dueRows()->sum('due'), 2);
    }
}
