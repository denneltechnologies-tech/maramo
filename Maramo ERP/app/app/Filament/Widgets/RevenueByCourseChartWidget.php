<?php

namespace App\Filament\Widgets;

use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Models\FeePayment;
use Filament\Widgets\ChartWidget;

class RevenueByCourseChartWidget extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Revenue Collected by Training Program (GH₵)';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $courses = SchoolClass::query()->orderBy('numeric_order')->get();
        $labels = [];
        $data = [];

        foreach ($courses as $c) {
            $labels[] = $c->name;
            $revenue = FeePayment::query()
                ->where('status', 'completed')
                ->whereHas('student', fn ($q) => $q->where('class_id', $c->id))
                ->sum('paid_amount');
            $data[] = round((float) $revenue, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Total Revenue (GH₵)',
                    'data' => $data,
                    'backgroundColor' => [
                        'rgba(197, 155, 39, 0.8)',
                        'rgba(212, 175, 55, 0.8)',
                        'rgba(184, 137, 75, 0.8)',
                        'rgba(45, 34, 28, 0.8)',
                        'rgba(226, 202, 160, 0.8)',
                    ],
                    'borderColor' => [
                        '#c59b27',
                        '#d4af37',
                        '#b8894b',
                        '#2d221c',
                        '#e2caa0',
                    ],
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
