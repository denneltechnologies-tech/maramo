<?php

namespace Classivo\Fees\Filament\Resources\FeeTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Fees Type create/edit form: Name, Code (unique), Description, Is system
 * type (transport/hostel auto), Status.
 */
class FeeTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Fees Type'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('e.g. Tuition Fee, Admission Fee'))
                            ->required()
                            ->maxLength(120),
                        TextInput::make('code')
                            ->placeholder(__('e.g. TUIT'))
                            ->maxLength(40),
                        TextInput::make('description')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Toggle::make('is_system')
                            ->label(__('System type (transport/hostel auto)'))
                            ->helperText(__('For heads raised automatically by other modules (transport, hostel).'))
                            ->default(false),
                        Toggle::make('status')
                            ->label(__('Active'))
                            ->default(true),
                    ]),
            ]);
    }
}
