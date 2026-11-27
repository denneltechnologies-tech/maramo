<?php

namespace Classivo\Students\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Students\Filament\Resources\Pages\ManageStudentCategories;
use Classivo\Students\Models\StudentCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class StudentCategoryResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'students.categories';

    protected static ?string $model = StudentCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-students::students.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-students::students.resources.category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-students::students.resources.categories');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-students::students.fields.'.$k);

        return $schema->components([
            TextInput::make('name')->label($f('name'))->required()->maxLength(100),
            Textarea::make('description')->label($f('description')),
            Toggle::make('status')->label($f('active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-students::students.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('students_count')->label($f('students_count'))->counts('students')->badge(),
                ToggleColumn::make('status')->label($f('active'))->disabled(fn (): bool => static::cannotUpdate()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStudentCategories::route('/')];
    }
}
