<?php

namespace Classivo\Exams\Filament\Resources\Exams\Schemas;

use Classivo\Core\Models\SchoolClass;
use Classivo\Exams\Models\ExamGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Exam create/edit form: exam group, name, optional class scope,
 * description, start/end dates, show-rank toggle, weightage, status. Result
 * publishing is a table action (gated), not a form field.
 */
class ExamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('classivo-exams::ui.exam'))
                    ->columns(2)
                    ->schema([
                        Select::make('exam_group_id')
                            ->label(__('classivo-exams::ui.exam_group'))
                            ->options(fn (): array => ExamGroup::query()
                                ->where('status', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label(__('classivo-exams::ui.exam_name'))
                            ->placeholder(__('classivo-exams::ui.e_g_mid_term'))
                            ->required()
                            ->maxLength(150),
                        Select::make('class_id')
                            ->label(__('classivo-exams::ui.class_scope_optional'))
                            ->placeholder(__('classivo-exams::ui.all_classes'))
                            ->options(fn (): array => SchoolClass::query()
                                ->orderBy('numeric_order')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                        TextInput::make('weightage')
                            ->label(__('classivo-exams::ui.weightage'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100),
                        DatePicker::make('start_date')->label(__('classivo-exams::ui.start_date')),
                        DatePicker::make('end_date')->label(__('classivo-exams::ui.end_date')),
                        Textarea::make('description')->label(__('classivo-exams::ui.field_description'))->rows(2)->columnSpanFull(),
                        Toggle::make('show_rank')->label(__('classivo-exams::ui.show_rank_position'))->default(true),
                        Toggle::make('status')->label(__('classivo-exams::ui.active'))->default(true),
                    ]),
            ]);
    }
}
