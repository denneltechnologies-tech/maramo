<?php

namespace Classivo\Fees\Filament\Resources\FeeDiscounts\Schemas;

use Classivo\Fees\Models\FeeDiscount;
use Classivo\Fees\Support\Labels;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Fees Discount create/edit form: Name, Code, Type (Fixed/Percentage),
 * Amount/percent, Description, Valid from/to, Status.
 */
class FeeDiscountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Fees Discount'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('e.g. Sibling discount, Staff ward'))
                            ->required()
                            ->maxLength(150),
                        TextInput::make('code')
                            ->maxLength(40),
                        Radio::make('type')
                            ->options(Labels::t(FeeDiscount::TYPES))
                            ->default('fixed')
                            ->inline()
                            ->live()
                            ->required(),
                        TextInput::make('value')
                            ->label(fn (Get $get): string => $get('type') === 'percent' ? __('Percentage') : __('Amount'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix(fn (Get $get): ?string => $get('type') === 'percent' ? '%' : null),
                        DatePicker::make('valid_from')->native(false),
                        DatePicker::make('valid_to')->native(false),
                        TextInput::make('description')->maxLength(255)->columnSpanFull(),
                        Toggle::make('status')->label(__('Active'))->default(true),
                    ]),
            ]);
    }
}
