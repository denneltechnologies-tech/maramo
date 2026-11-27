<?php

namespace Classivo\ReportCards\Filament\Resources\TransferCertificates;

use BackedEnum;
use Classivo\ReportCards\Adapters\ClassivoAdapter;
use Classivo\ReportCards\Filament\Concerns\AuthorizesThroughCanMethods;
use Classivo\ReportCards\Filament\Concerns\ChecksPermissions;
use Classivo\ReportCards\Filament\Concerns\SelectsStudent;
use Classivo\ReportCards\Filament\Resources\TransferCertificates\Pages\ManageTransferCertificates;
use Classivo\ReportCards\Models\TransferCertificate;
use Classivo\ReportCards\Renderer\PdfRenderer;
use Classivo\ReportCards\Services\InstitutionResolver;
use Classivo\ReportCards\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TransferCertificateResource extends Resource
{
    use AuthorizesThroughCanMethods;
    use ChecksPermissions, SelectsStudent;

    protected static ?string $model = TransferCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?int $navigationSort = 34;

    protected static function permissionKey(): string
    {
        return 'transfer_certificates';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('report-cards::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('report-cards::ui.transfer_certificates');
    }

    public static function getModelLabel(): string
    {
        return __('report-cards::ui.transfer_certificate');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            static::studentSelect(),
            DatePicker::make('date_of_leaving')->label(__('report-cards::ui.date_of_leaving')),
            TextInput::make('last_class_attended')->label(__('report-cards::ui.last_class')),
            TextInput::make('reason_for_leaving')->label(__('report-cards::ui.reason')),
            TextInput::make('conduct')->label(__('report-cards::ui.conduct')),
            TextInput::make('total_working_days')->label(__('report-cards::ui.working_days'))->numeric()->minValue(0),
            TextInput::make('days_present')->label(__('report-cards::ui.days_present'))->numeric()->minValue(0),
            TextInput::make('promotion_status')->label(__('report-cards::ui.promotion_status')),
            Toggle::make('dues_cleared')->label(__('report-cards::ui.dues_cleared')),
            Textarea::make('remarks')->label(__('report-cards::ui.remarks'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tc_number')->label(__('report-cards::ui.number'))->searchable(),
                TextColumn::make('student.full_name')->label(__('report-cards::ui.student')),
                TextColumn::make('date_of_leaving')->label(__('report-cards::ui.date_of_leaving'))->date(),
                IconColumn::make('dues_cleared')->label(__('report-cards::ui.dues_cleared'))->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label(__('report-cards::ui.download_pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => Permissions::allows('transfer_certificates.download'))
                    ->action(function (TransferCertificate $record) {
                        abort_unless(Permissions::allows('transfer_certificates.download'), 403);

                        $record->loadMissing('student.guardians');
                        $resolver = app(InstitutionResolver::class);
                        $data = app(ClassivoAdapter::class)->transferCertificate(
                            $resolver->school(), current_branch(), $record->student, $record, auth()->user()?->name,
                        );
                        $pdf = app(PdfRenderer::class)->transferCertificate($data);

                        return response()->streamDownload(fn () => print ($pdf), 'transfer-certificate-'.$record->tc_number.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                EditAction::make()->authorize(fn ($record): bool => static::canEdit($record)),
                DeleteAction::make()->authorize(fn ($record): bool => static::canDelete($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTransferCertificates::route('/')];
    }
}
