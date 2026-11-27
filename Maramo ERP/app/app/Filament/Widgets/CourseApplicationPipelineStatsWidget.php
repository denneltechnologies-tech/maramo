<?php

namespace App\Filament\Widgets;

use App\Models\CourseApplication;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CourseApplicationPipelineStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $total = CourseApplication::count();
        $pending = CourseApplication::where('status', 'pending')->count();
        $approved = CourseApplication::where('status', 'approved')->count();
        $enrolled = CourseApplication::where('status', 'enrolled')->count();
        $conversion = $total > 0 ? round(($enrolled / $total) * 100, 1) : 0;

        return [
            Stat::make('Total Applications & Leads', (string) $total)
                ->description('All website & walk-in applicants')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary'),

            Stat::make('Pending Review', (string) $pending)
                ->description('Requires staff action')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Approved for Admission', (string) $approved)
                ->description('Ready for student enrollment')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Enrolled Trainees', "{$enrolled} ({$conversion}%)")
                ->description('Converted to active students')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),
        ];
    }
}
