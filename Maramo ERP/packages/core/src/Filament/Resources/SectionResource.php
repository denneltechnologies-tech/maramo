<?php

namespace Classivo\Core\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Core\Filament\Resources\Pages\ManageSections;
use Classivo\Core\Models\Section;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SectionResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'core.sections';

    protected static ?string $model = Section::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-core::core.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-core::core.resources.section');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-core::core.resources.sections');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $schema->columns(2)->components([
            TextInput::make('name')->label($f('name'))->placeholder('A')->required()->maxLength(50),
            TextInput::make('capacity')->label($f('capacity'))->numeric()->minValue(1),
            Toggle::make('status')->label($f('status'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('capacity')->label($f('capacity'))->placeholder('—'),
                TextColumn::make('class_sections_count')->label(__('classivo-core::core.resources.classes'))->counts('classSections')->badge(),
                ToggleColumn::make('status')->label($f('status'))->disabled(fn (): bool => static::cannotUpdate()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSections::route('/')];
    }
}
