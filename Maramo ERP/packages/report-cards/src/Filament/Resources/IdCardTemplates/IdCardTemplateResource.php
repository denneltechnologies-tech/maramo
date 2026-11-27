<?php

namespace Classivo\ReportCards\Filament\Resources\IdCardTemplates;

use BackedEnum;
use Classivo\ReportCards\Filament\Concerns\AuthorizesThroughCanMethods;
use Classivo\ReportCards\Filament\Concerns\ChecksPermissions;
use Classivo\ReportCards\Filament\Resources\IdCardTemplates\Pages\ManageIdCardTemplates;
use Classivo\ReportCards\Models\IdCardTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class IdCardTemplateResource extends Resource
{
    use AuthorizesThroughCanMethods;
    use ChecksPermissions;

    protected static ?string $model = IdCardTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 32;

    protected static function permissionKey(): string
    {
        return 'id_card_templates';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('report-cards::ui.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('report-cards::ui.id_card_templates');
    }

    public static function getModelLabel(): string
    {
        return __('report-cards::ui.id_card_template');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('report-cards::ui.name'))->required()->maxLength(255),
            Select::make('type')->label(__('report-cards::ui.type'))->options(IdCardTemplate::TYPES)->default('student')->required()->live(),
            CheckboxList::make('fields')->label(__('report-cards::ui.fields'))
                ->options(fn (Get $get): array => $get('type') === 'staff' ? IdCardTemplate::STAFF_FIELDS : IdCardTemplate::STUDENT_FIELDS)
                ->columns(2)->columnSpanFull(),
            ColorPicker::make('header_color')->label(__('report-cards::ui.header_color'))->default('#1e293b'),
            ColorPicker::make('text_color')->label(__('report-cards::ui.text_color'))->default('#1e293b'),
            Select::make('orientation')->label(__('report-cards::ui.orientation'))->options(['landscape' => 'Landscape', 'portrait' => 'Portrait'])->default('landscape')->required(),
            Toggle::make('show_photo')->label(__('report-cards::ui.show_photo'))->default(true),
            Toggle::make('show_qr')->label(__('report-cards::ui.show_qr'))->default(true),
            Toggle::make('show_logo')->label(__('report-cards::ui.show_logo'))->default(true),
            Toggle::make('is_default')->label(__('report-cards::ui.is_default')),
            Toggle::make('status')->label(__('report-cards::ui.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('report-cards::ui.name'))->searchable(),
                TextColumn::make('type')->label(__('report-cards::ui.type'))->badge()->formatStateUsing(fn ($state) => IdCardTemplate::TYPES[$state] ?? $state),
                IconColumn::make('is_default')->label(__('report-cards::ui.is_default'))->boolean(),
                IconColumn::make('status')->label(__('report-cards::ui.active'))->boolean(),
            ])
            ->recordActions([EditAction::make()->authorize(fn ($record): bool => static::canEdit($record)), DeleteAction::make()->authorize(fn ($record): bool => static::canDelete($record))]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageIdCardTemplates::route('/')];
    }
}
