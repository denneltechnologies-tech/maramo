<?php

namespace Classivo\Hr\Filament\Resources\Designations\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Designation create/edit form (spec §15: name, description, status).
 */
class DesignationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.designation'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('hr::ui.e_g_senior_teacher'))
                            ->required()
                            ->maxLength(150),
                        Toggle::make('status')
                            ->label(__('hr::ui.active'))
                            ->default(true),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
