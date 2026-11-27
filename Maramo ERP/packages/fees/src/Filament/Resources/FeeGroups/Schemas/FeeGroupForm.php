<?php

namespace Classivo\Fees\Filament\Resources\FeeGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Fees Group create/edit form: Group name, Description, Status. The
 * session is auto-stamped from the active session (BelongsToSession).
 */
class FeeGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Fees Group'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('e.g. Class 1 Annual Fees, April Monthly'))
                            ->required()
                            ->maxLength(150),
                        Toggle::make('status')
                            ->label(__('Active'))
                            ->default(true),
                        TextInput::make('description')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
