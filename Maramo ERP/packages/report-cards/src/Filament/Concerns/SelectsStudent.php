<?php

namespace Classivo\ReportCards\Filament\Concerns;

use Classivo\Students\Models\Student;
use Filament\Forms\Components\Select;

trait SelectsStudent
{
    protected static function studentSelect(string $name = 'student_id'): Select
    {
        return Select::make($name)
            ->label(__('report-cards::ui.student'))
            ->searchable()
            ->required()
            ->getSearchResultsUsing(fn (string $search): array => Student::query()
                ->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('admission_no', 'like', "%{$search}%"))
                ->limit(30)->get()
                ->mapWithKeys(fn ($s) => [$s->getKey() => $s->full_name.' ('.$s->admission_no.')'])->all())
            ->getOptionLabelUsing(fn ($value): ?string => ($s = Student::find($value)) ? $s->full_name.' ('.$s->admission_no.')' : null);
    }
}
