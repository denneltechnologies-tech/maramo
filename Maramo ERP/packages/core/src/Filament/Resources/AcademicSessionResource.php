<?php

namespace Classivo\Core\Filament\Resources;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesWithPermissions;
use Classivo\Core\Filament\Resources\Pages\ManageAcademicSessions;
use Classivo\Core\Models\AcademicSession;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcademicSessionResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static string $permissionPrefix = 'core.academic_sessions';

    protected static ?string $model = AcademicSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-core::core.settings_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-core::core.resources.academic_session');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-core::core.resources.academic_sessions');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $schema->columns(2)->components([
            TextInput::make('name')->label($f('name'))->placeholder('2026-2027')->required()->maxLength(50),
            Toggle::make('is_current')->label($f('is_current')),
            DatePicker::make('start_date')->label($f('start_date')),
            DatePicker::make('end_date')->label($f('end_date'))->afterOrEqual('start_date'),
            Toggle::make('status')->label($f('status'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k): string => __('classivo-core::core.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('start_date')->label($f('start_date'))->date(),
                TextColumn::make('end_date')->label($f('end_date'))->date(),
                IconColumn::make('is_current')->label($f('is_current'))->boolean(),
            ])
            ->recordActions([
                Action::make('setCurrent')
                    ->label(__('classivo-core::core.actions.set_current'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn (AcademicSession $record): bool => ! $record->is_current)
                    ->action(fn (AcademicSession $record) => $record->update(['is_current' => true])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('start_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAcademicSessions::route('/')];
    }
}
