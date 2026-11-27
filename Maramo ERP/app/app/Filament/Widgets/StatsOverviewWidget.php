<?php

namespace App\Filament\Widgets;

use App\Models\CourseApplication;
use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\Money;
use Classivo\Students\Models\Student;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $symbol = Money::symbol();

        $totalStudents = Student::query()->count();
        $activeStudents = Student::query()->where('status', 'active')->count();
        $completedStudents = Student::query()->where('status', 'completed')->count();
        $pendingApplications = CourseApplication::query()->where('status', 'pending')->count();
        $activeCourses = SchoolClass::query()->count();

        // Financial figures
        $totalExpected = FeeStudentAllocation::query()->sum('amount') ?? 0;
        $totalCollected = FeePayment::query()->where('status', 'completed')->sum('paid_amount') ?? 0;
        $outstandingBalance = max(0, $totalExpected - $totalCollected);

        $monthCollected = FeePayment::query()
            ->where('status', 'completed')
            ->whereBetween('payment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('paid_amount') ?? 0;

        $yearCollected = FeePayment::query()
            ->where('status', 'completed')
            ->whereBetween('payment_date', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()])
            ->sum('paid_amount') ?? 0;

        return [
            Stat::make('Total Registered Students', (string) $totalStudents)
                ->description("{$activeStudents} currently in training")
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('primary'),

            Stat::make('New Applications', (string) $pendingApplications)
                ->description('Awaiting review')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color($pendingApplications > 0 ? 'warning' : 'gray'),

            Stat::make('Completed / Alumni', (string) $completedStudents)
                ->description('Graduated trainees')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Active Courses', (string) $activeCourses)
                ->description('Beauty training programs')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('info'),

            Stat::make('Total Fees Expected', "{$symbol}" . number_format($totalExpected, 2))
                ->description('Total course allocations')
                ->color('gray'),

            Stat::make('Total Fees Collected', "{$symbol}" . number_format($totalCollected, 2))
                ->description('All recorded payments')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Outstanding Balance', "{$symbol}" . number_format($outstandingBalance, 2))
                ->description('Pending student fees')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($outstandingBalance > 0 ? 'danger' : 'success'),

            Stat::make('Revenue (This Month)', "{$symbol}" . number_format($monthCollected, 2))
                ->description("Annual: {$symbol}" . number_format($yearCollected, 2))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),
        ];
    }
}
