<?php

namespace Classivo\Hr\Filament\Resources\Departments\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Department create/edit form (spec §15: name, description, status).
 */
class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('hr::ui.department'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('hr::ui.e_g_teaching'))
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
