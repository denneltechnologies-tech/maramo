<?php

namespace Classivo\Timetable\Filament\Resources\Substitutions;

use BackedEnum;
use Classivo\Core\Filament\Concerns\AuthorizesThroughCanMethods;
use Classivo\Timetable\Filament\Resources\Substitutions\Pages\ListSubstitutions;
use Classivo\Timetable\Models\Substitution;
use Classivo\Timetable\Models\TimetablePeriod;
use Classivo\Timetable\Services\SubstitutionService;
use Classivo\Timetable\Support\Authorize;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Substitution register: who covered which period on which date. View with `view`. */
class SubstitutionResource extends Resource
{
    use AuthorizesThroughCanMethods;

    protected static ?string $model = Substitution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Authorize::can('view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('classivo-timetable::timetable.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('classivo-timetable::timetable.substitution.record');
    }

    public static function getPluralModelLabel(): string
    {
        return __('classivo-timetable::timetable.substitution.register');
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __('classivo-timetable::timetable.substitution.'.$key);

        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['period.timetable.classSection.schoolClass', 'period.timetable.classSection.section', 'period.subject', 'absentTeacher', 'substitute']))
            ->columns([
                TextColumn::make('date')->label($t('date'))->date()->sortable(),
                TextColumn::make('period_time')->label(__('classivo-timetable::timetable.form.period'))
                    ->state(fn (Substitution $r): string => $r->period?->period_no.' · '.TimetablePeriod::formatTime($r->period?->start_time).'–'.TimetablePeriod::formatTime($r->period?->end_time)),
                TextColumn::make('class')->label(__('classivo-timetable::timetable.form.class'))->state(fn (Substitution $r): ?string => $r->period?->timetable?->classSection?->name),
                TextColumn::make('subject')->label(__('classivo-timetable::timetable.form.subject'))->state(fn (Substitution $r): ?string => $r->period?->subject?->name),
                TextColumn::make('absent')->label($t('teacher'))->state(fn (Substitution $r): ?string => $r->absentTeacher?->name),
                TextColumn::make('covering')->label($t('substitute'))->state(fn (Substitution $r): ?string => $r->substitute?->name)->placeholder('—'),
                TextColumn::make('status')->label($t('status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('classivo-timetable::timetable.substitution.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        Substitution::STATUS_ASSIGNED => 'success',
                        Substitution::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reason')->label($t('reason'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label($t('status'))->options(collect([Substitution::STATUS_PENDING, Substitution::STATUS_ASSIGNED, Substitution::STATUS_CANCELLED])
                    ->mapWithKeys(fn ($s) => [$s => __('classivo-timetable::timetable.substitution.statuses.'.$s)])->all()),
                Filter::make('between')
                    ->schema([
                        DatePicker::make('from')->label($t('from')),
                        DatePicker::make('until')->label($t('until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label($t('cancel'))
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Substitution $r): bool => $r->status !== Substitution::STATUS_CANCELLED && Authorize::can('substitute'))
                    ->action(function (Substitution $record): void {
                        abort_unless(Authorize::can('substitute'), 403);
                        app(SubstitutionService::class)->cancel($record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubstitutions::route('/')];
    }
}
