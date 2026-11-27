<?php

namespace Classivo\Core\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Core\Filament\Resources\Pages\ManageBranches;
use Classivo\Core\Models\Branch;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'core.branches';

    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-core::core.settings_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-core::core.resources.branch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-core::core.resources.branches');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $schema->columns(2)->components([
            TextInput::make('name')->label($f('name'))->required()->maxLength(255),
            TextInput::make('code')->label($f('code'))->required()->maxLength(30),
            TextInput::make('principal_name')->label($f('principal_name')),
            TextInput::make('phone')->label($f('phone'))->tel(),
            TextInput::make('email')->label($f('email'))->email(),
            TextInput::make('city')->label($f('city')),
            TextInput::make('address')->label($f('address'))->columnSpanFull(),
            TextInput::make('reg_no_prefix')->label($f('reg_no_prefix'))->placeholder('ADM'),
            TextInput::make('reg_no_start')->label($f('reg_no_start'))->numeric()->default(1),
            TextInput::make('reg_no_digits')->label($f('reg_no_digits'))->numeric()->default(4),
            CheckboxList::make('weekend_days')
                ->label($f('weekend_days'))
                ->options(fn (): array => (array) __('classivo-core::core.weekdays'))
                ->columns(4)
                ->columnSpanFull(),
            Toggle::make('is_default')->label($f('is_default')),
            Toggle::make('status')->label($f('status'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('code')->label($f('code'))->badge(),
                TextColumn::make('city')->label($f('city'))->placeholder('—'),
                TextColumn::make('currency')->label($f('currency')),
                IconColumn::make('is_default')->label($f('is_default'))->boolean(),
                IconColumn::make('status')->label($f('status'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBranches::route('/')];
    }
}
