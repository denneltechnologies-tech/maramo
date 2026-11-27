<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Services\Reports\FeeReportService;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fee reports — collection, collection by mode / class / fee head, outstanding by
 * fee head, overdue ageing, concessions given and reverted receipts, each with
 * totals and a CSV download.
 */
class FeeReports extends FeesPage implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 52;

    protected string $view = 'classivo-fees::filament.pages.fee-reports';

    public ?array $data = [];

    public static function permission(): string
    {
        return FeesPermissions::REPORTS_VIEW;
    }

    protected static function englishTitle(): string
    {
        return 'Fee Reports';
    }

    public function mount(): void
    {
        $this->form->fill([
            'report' => 'collection',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'class_id' => null,
            'mode' => null,
            'as_of' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $usesDates = fn (Get $get): bool => ! in_array($get('report'), ['head_outstanding', 'ageing'], true);

        return $schema
            ->components([
                FormSection::make(__('Filters'))
                    ->columns(3)
                    ->schema([
                        Select::make('report')
                            ->label(__('Report'))
                            ->options(fn (): array => array_map(fn (string $l): string => __($l), FeeReportService::REPORTS))
                            ->required()
                            ->live(),
                        DatePicker::make('from')->label(__('From'))->native(false)->live()->visible($usesDates),
                        DatePicker::make('to')->label(__('To'))->native(false)->live()->visible($usesDates),
                        DatePicker::make('as_of')->label(__('As of'))->native(false)->live()
                            ->visible(fn (Get $get): bool => $get('report') === 'ageing'),
                        Select::make('class_id')
                            ->label(__('Class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live(),
                        Select::make('mode')
                            ->label(__('Payment mode'))
                            ->options(fn (): array => array_map(fn (string $l): string => __($l), FeePayment::MODES))
                            ->live()
                            ->visible($usesDates),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array{columns: array<string, string>, money: list<string>, rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    public function result(): array
    {
        return app(FeeReportService::class)->run((string) ($this->data['report'] ?? 'collection'), $this->data ?? []);
    }

    public function format(string $key, mixed $value, array $money): string
    {
        return in_array($key, $money, true) ? Money::format($value) : (string) ($value ?? '—');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCsv')
                ->label(__('Download CSV'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadCsv()),
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        $result = $this->result();
        $name = 'fee-report-'.($this->data['report'] ?? 'collection').'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn (string $l): string => __($l), array_values($result['columns'])));

            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(fn (string $key) => $row[$key] ?? '', array_keys($result['columns'])));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
