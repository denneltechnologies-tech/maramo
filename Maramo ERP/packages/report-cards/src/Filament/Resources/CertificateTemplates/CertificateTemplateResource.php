<?php

namespace Classivo\ReportCards\Filament\Resources\CertificateTemplates;

use BackedEnum;
use Classivo\ReportCards\Filament\Concerns\AuthorizesThroughCanMethods;
use Classivo\ReportCards\Filament\Concerns\ChecksPermissions;
use Classivo\ReportCards\Filament\Resources\CertificateTemplates\Pages\ManageCertificateTemplates;
use Classivo\ReportCards\Models\CertificateTemplate;
use Classivo\ReportCards\Renderer\CertificateRenderer;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

class CertificateTemplateResource extends Resource
{
    use AuthorizesThroughCanMethods;
    use ChecksPermissions;

    protected static ?string $model = CertificateTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 31;

    protected static function permissionKey(): string
    {
        return 'certificate_templates';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('report-cards::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('report-cards::ui.certificate_templates');
    }

    public static function getModelLabel(): string
    {
        return __('report-cards::ui.certificate_template');
    }

    public static function form(Schema $schema): Schema
    {
        $tags = collect(CertificateRenderer::availableVariables())->keys()->map(fn ($k) => '{{'.$k.'}}')->implode('  ');

        return $schema->components([
            TextInput::make('name')->label(__('report-cards::ui.name'))->required()->maxLength(255),
            Select::make('type')->label(__('report-cards::ui.type'))->options(CertificateTemplate::TYPES)->default('student')->required(),
            Textarea::make('content')->label(__('report-cards::ui.content'))->rows(10)->required()->helperText(__('report-cards::ui.variables_hint').' '.$tags)->columnSpanFull(),
            Select::make('page_size')->label(__('report-cards::ui.page_size'))->options(['a4' => 'A4', 'a5' => 'A5', 'letter' => 'Letter'])->default('a4')->required(),
            Select::make('orientation')->label(__('report-cards::ui.orientation'))->options(['portrait' => 'Portrait', 'landscape' => 'Landscape'])->default('portrait')->required(),
            Toggle::make('is_default')->label(__('report-cards::ui.is_default')),
            Toggle::make('status')->label(__('report-cards::ui.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('report-cards::ui.name'))->searchable(),
                TextColumn::make('type')->label(__('report-cards::ui.type'))->badge()->formatStateUsing(fn ($state) => CertificateTemplate::TYPES[$state] ?? $state),
                IconColumn::make('is_default')->label(__('report-cards::ui.is_default'))->boolean(),
                IconColumn::make('status')->label(__('report-cards::ui.active'))->boolean(),
            ])
            ->recordActions([EditAction::make()->authorize(fn ($record): bool => static::canEdit($record)), DeleteAction::make()->authorize(fn ($record): bool => static::canDelete($record))]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCertificateTemplates::route('/')];
    }
}
