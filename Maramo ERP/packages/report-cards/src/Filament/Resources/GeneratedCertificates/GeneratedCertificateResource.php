<?php

namespace Classivo\ReportCards\Filament\Resources\GeneratedCertificates;

use BackedEnum;
use Classivo\ReportCards\Filament\Concerns\AuthorizesThroughCanMethods;
use Classivo\ReportCards\Filament\Concerns\ChecksPermissions;
use Classivo\ReportCards\Filament\Resources\GeneratedCertificates\Pages\ManageGeneratedCertificates;
use Classivo\ReportCards\Models\GeneratedCertificate;
use Classivo\ReportCards\Services\CertificateIssuer;
use Classivo\ReportCards\Services\InstitutionResolver;
use Classivo\ReportCards\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Issued-certificate log. No create/edit form: certificates are issued from the header action. */
class GeneratedCertificateResource extends Resource
{
    use AuthorizesThroughCanMethods;
    use ChecksPermissions;

    protected static ?string $model = GeneratedCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 33;

    protected static function permissionKey(): string
    {
        return 'generated_certificates';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('report-cards::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('report-cards::ui.generated_certificates');
    }

    public static function getModelLabel(): string
    {
        return __('report-cards::ui.generated_certificate');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('certificate_number')->label(__('report-cards::ui.number'))->searchable(),
                TextColumn::make('certificateTemplate.name')->label(__('report-cards::ui.template')),
                TextColumn::make('student.full_name')->label(__('report-cards::ui.student')),
                TextColumn::make('issued_at')->label(__('report-cards::ui.issued_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label(__('report-cards::ui.download_pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => Permissions::allows('generated_certificates.download'))
                    ->action(function (GeneratedCertificate $record) {
                        abort_unless(Permissions::allows('generated_certificates.download'), 403);

                        $path = app(CertificateIssuer::class)->storePdf($record, app(InstitutionResolver::class)->current());

                        return Storage::disk((string) config('report-cards.disk', 'local'))->download($path);
                    }),
                DeleteAction::make()->authorize(fn ($record): bool => static::canDelete($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGeneratedCertificates::route('/')];
    }
}
