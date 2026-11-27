<?php

namespace Classivo\Core\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Core\Filament\Resources\Pages\ManageSubjects;
use Classivo\Core\Models\Subject;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SubjectResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'core.subjects';

    protected static ?string $model = Subject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-core::core.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-core::core.resources.subject');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-core::core.resources.subjects');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $schema->columns(2)->components([
            TextInput::make('name')->label($f('name'))->required()->maxLength(255),
            TextInput::make('code')->label($f('code'))->maxLength(30),
            Select::make('type')->label($f('type'))->options(fn (): array => Subject::typeOptions())->default('theory')->required(),
            Toggle::make('status')->label($f('status'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('code')->label($f('code'))->placeholder('—'),
                TextColumn::make('type')->label($f('type'))->badge()
                    ->formatStateUsing(fn (?string $state): string => Subject::typeOptions()[$state] ?? (string) $state),
                ToggleColumn::make('status')->label($f('status'))->disabled(fn (): bool => static::cannotUpdate()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSubjects::route('/')];
    }
}
