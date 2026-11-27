<?php

namespace Classivo\ReportCards\Filament\Resources\ReportCardTemplates;

use BackedEnum;
use Classivo\ReportCards\Filament\Concerns\AuthorizesThroughCanMethods;
use Classivo\ReportCards\Filament\Concerns\ChecksPermissions;
use Classivo\ReportCards\Filament\Resources\ReportCardTemplates\Pages\ManageReportCardTemplates;
use Classivo\ReportCards\Models\ReportCardTemplate;
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

class ReportCardTemplateResource extends Resource
{
    use AuthorizesThroughCanMethods;
    use ChecksPermissions;

    protected static ?string $model = ReportCardTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 30;

    protected static function permissionKey(): string
    {
        return 'report_card_templates';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('report-cards::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('report-cards::ui.report_card_templates');
    }

    public static function getModelLabel(): string
    {
        return __('report-cards::ui.report_card_template');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('report-cards::ui.name'))->required()->maxLength(255),
            Select::make('type')->label(__('report-cards::ui.type'))->options(ReportCardTemplate::TYPES)->default('marksheet')->required(),
            Select::make('layout')->label(__('report-cards::ui.layout'))->options(['generic' => 'Generic', 'cbse' => 'CBSE-style'])->default('generic')->required(),
            Select::make('page_size')->label(__('report-cards::ui.page_size'))->options(['a4' => 'A4', 'a5' => 'A5', 'letter' => 'Letter'])->default('a4')->required(),
            Select::make('orientation')->label(__('report-cards::ui.orientation'))->options(['portrait' => 'Portrait', 'landscape' => 'Landscape'])->default('portrait')->required(),
            Textarea::make('header_html')->label(__('report-cards::ui.header_html'))->columnSpanFull(),
            Textarea::make('footer_html')->label(__('report-cards::ui.footer_html'))->columnSpanFull(),
            Toggle::make('is_default')->label(__('report-cards::ui.is_default')),
            Toggle::make('status')->label(__('report-cards::ui.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('report-cards::ui.name'))->searchable(),
                TextColumn::make('type')->label(__('report-cards::ui.type'))->badge()->formatStateUsing(fn ($state) => ReportCardTemplate::TYPES[$state] ?? $state),
                TextColumn::make('layout')->label(__('report-cards::ui.layout')),
                IconColumn::make('is_default')->label(__('report-cards::ui.is_default'))->boolean(),
                IconColumn::make('status')->label(__('report-cards::ui.active'))->boolean(),
            ])
            ->recordActions([EditAction::make()->authorize(fn ($record): bool => static::canEdit($record)), DeleteAction::make()->authorize(fn ($record): bool => static::canDelete($record))]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportCardTemplates::route('/')];
    }
}
