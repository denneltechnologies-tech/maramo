<?php

namespace Classivo\Core\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Core\Filament\Resources\Pages\ManageSchoolClasses;
use Classivo\Core\Models\SchoolClass;
use Classivo\Core\Models\Section;
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
use Illuminate\Support\Arr;

class SchoolClassResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'core.classes';

    protected static ?string $model = SchoolClass::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-core::core.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-core::core.resources.class');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-core::core.resources.classes');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $schema->columns(2)->components([
            TextInput::make('name')->label($f('name'))->placeholder('Grade 1')->required()->maxLength(100),
            TextInput::make('numeric_order')->label($f('numeric_order'))->numeric()->default(0),
            Select::make('section_ids')
                ->label($f('sections'))
                ->multiple()
                ->options(fn (): array => Section::query()->orderBy('name')->pluck('name', 'id')->all())
                ->dehydrated(),
            Toggle::make('status')->label($f('status'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('sections.name')->label($f('sections'))->badge(),
                TextColumn::make('numeric_order')->label($f('numeric_order'))->sortable(),
                ToggleColumn::make('status')->label($f('status'))->disabled(fn (): bool => static::cannotUpdate()),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, SchoolClass $record): array {
                        $data['section_ids'] = $record->sections()->pluck('sections.id')->all();

                        return $data;
                    })
                    ->using(function (SchoolClass $record, array $data): SchoolClass {
                        $sectionIds = (array) Arr::pull($data, 'section_ids', []);
                        $record->update($data);
                        $record->syncSections($sectionIds);

                        return $record;
                    }),
                DeleteAction::make(),
            ])
            ->defaultSort('numeric_order');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSchoolClasses::route('/')];
    }
}
