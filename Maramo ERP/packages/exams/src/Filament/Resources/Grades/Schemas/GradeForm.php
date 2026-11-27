<?php

namespace Classivo\Exams\Filament\Resources\Grades\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Grade create/edit form: name, percent range, grade point, remark,
 * is-fail flag.
 */
class GradeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('classivo-exams::ui.grade_band'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('classivo-exams::ui.grade_name'))
                            ->placeholder(__('classivo-exams::ui.e_g_a'))
                            ->required()
                            ->maxLength(20),
                        TextInput::make('grade_point')
                            ->label(__('classivo-exams::ui.grade_point_gpa'))
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0),
                        TextInput::make('min_percent')
                            ->label(__('classivo-exams::ui.percent_from'))
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100),
                        TextInput::make('max_percent')
                            ->label(__('classivo-exams::ui.percent_up_to'))
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100),
                        TextInput::make('remark')->label(__('classivo-exams::ui.remark'))->maxLength(150),
                        Toggle::make('is_fail')->label(__('classivo-exams::ui.is_a_failing_grade'))->default(false),
                    ]),
            ]);
    }
}
