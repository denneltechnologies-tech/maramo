<?php

namespace Classivo\Exams\Filament\Resources\ExamGroups\Schemas;

use Classivo\Exams\Models\ExamGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Exam-group create/edit form: name, assessment type, description, status.
 */
class ExamGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('classivo-exams::ui.exam_group'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('classivo-exams::ui.exam_group_name'))
                            ->placeholder(__('classivo-exams::ui.e_g_term_1'))
                            ->required()
                            ->maxLength(150)
                            // Matches exam_groups_branch_id_session_id_name_unique.
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('branch_id', current_branch_id())->where('session_id', current_session_id())->whereNull('deleted_at')),
                        Select::make('type')
                            ->label(__('classivo-exams::ui.exam_type'))
                            ->options(ExamGroup::typeOptions())
                            ->default('marks')
                            ->required(),
                        Textarea::make('description')->label(__('classivo-exams::ui.field_description'))
                            ->rows(2)
                            ->columnSpanFull(),
                        Toggle::make('status')
                            ->label(__('classivo-exams::ui.active'))
                            ->default(true),
                    ]),
            ]);
    }
}
