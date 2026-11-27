<?php

namespace App\Filament\Widgets;

use App\Models\CourseApplication;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentApplicationsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CourseApplication::query()->latest()->limit(5)
            )
            ->heading('Recent Online & Walk-in Applications')
            ->columns([
                TextColumn::make('application_no')->label('App No.')->searchable(),
                TextColumn::make('full_name')->label('Applicant Name'),
                TextColumn::make('phone')->label('Phone / WhatsApp'),
                TextColumn::make('course.name')->label('Program'),
                TextColumn::make('preferred_schedule')->label('Schedule')
                    ->formatStateUsing(fn ($state) => CourseApplication::SCHEDULES[$state] ?? ucfirst($state)),
                TextColumn::make('source')->label('Source')->badge()
                    ->formatStateUsing(fn ($state) => CourseApplication::SOURCES[$state] ?? ucfirst($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'under_review' => 'info',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'enrolled' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => CourseApplication::STATUSES[$state] ?? ucfirst($state)),
                TextColumn::make('created_at')->label('Applied Date')->since(),
            ]);
    }
}
