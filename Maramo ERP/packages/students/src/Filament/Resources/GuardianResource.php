<?php

namespace Classivo\Students\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Students\Filament\Resources\Pages\ManageGuardians;
use Classivo\Students\Models\Guardian;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GuardianResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'students.guardians';

    protected static ?string $model = Guardian::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-students::students.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-students::students.resources.guardian');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-students::students.resources.guardians');
    }

    /** @return array<string, string> */
    public static function relationOptions(): array
    {
        return (array) __('classivo-students::students.relations');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-students::students.fields.'.$k);

        return $schema->columns(2)->components([
            TextInput::make('name')->label($f('name'))->required()->maxLength(255),
            Select::make('relation')->label($f('relation'))->options(fn (): array => static::relationOptions())->default('other')->required(),
            TextInput::make('phone')->label($f('phone'))->tel(),
            TextInput::make('email')->label($f('email'))->email(),
            TextInput::make('occupation')->label($f('occupation')),
            Textarea::make('address')->label($f('address'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-students::students.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('relation')->label($f('relation'))->badge()
                    ->formatStateUsing(fn (?string $state): string => static::relationOptions()[$state] ?? (string) $state),
                TextColumn::make('phone')->label($f('phone'))->searchable()->placeholder('—'),
                TextColumn::make('email')->label($f('email'))->placeholder('—'),
                TextColumn::make('students_count')->label($f('students_count'))->counts('students')->badge(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageGuardians::route('/')];
    }
}
