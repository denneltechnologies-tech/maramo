<?php

namespace App\Filament\Resources\CourseApplicationResource\Pages;

use App\Filament\Resources\CourseApplicationResource;
use App\Filament\Widgets\CourseApplicationPipelineStatsWidget;
use App\Models\CourseApplication;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCourseApplications extends ListRecords
{
    protected static string $resource = CourseApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Register Walk-in Applicant')
                ->icon('heroicon-m-user-plus'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CourseApplicationPipelineStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Leads')
                ->badge(CourseApplication::count())
                ->icon('heroicon-m-squares-2x2'),

            'pending' => Tab::make('New Applications')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge(CourseApplication::where('status', 'pending')->count())
                ->badgeColor('warning')
                ->icon('heroicon-m-sparkles'),

            'under_review' => Tab::make('Under Review')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'under_review'))
                ->badge(CourseApplication::where('status', 'under_review')->count())
                ->badgeColor('info')
                ->icon('heroicon-m-eye'),

            'approved' => Tab::make('Approved for Admission')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved'))
                ->badge(CourseApplication::where('status', 'approved')->count())
                ->badgeColor('success')
                ->icon('heroicon-m-check-circle'),

            'enrolled' => Tab::make('Enrolled Students')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'enrolled'))
                ->badge(CourseApplication::where('status', 'enrolled')->count())
                ->badgeColor('primary')
                ->icon('heroicon-m-academic-cap'),

            'rejected' => Tab::make('Closed / Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected'))
                ->badge(CourseApplication::where('status', 'rejected')->count())
                ->badgeColor('gray')
                ->icon('heroicon-m-x-circle'),
        ];
    }
}
