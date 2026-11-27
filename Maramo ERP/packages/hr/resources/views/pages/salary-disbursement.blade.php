{{-- Salary Disbursement — the pre-flight report and the bank transfer file. --}}
@php($report = $this->report())

<x-filament-panels::page>
    {{ $this->form }}

    {{-- Summary tiles. The two that matter are "Ready to pay" and "Blocked": the
         second is the number of people who will NOT be paid if this file is
         uploaded as it stands. --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('hr::views.disburse.preflight', ['period' => $report['period']]) }}
        </h3>

        <div class="mt-4 grid gap-4 sm:grid-cols-4">
            <div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $report['payable'] }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('hr::views.disburse.ready') }}</div>
            </div>
            <div>
                <div @class([
                    'text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $report['blocked'] > 0,
                    'text-gray-950 dark:text-white' => $report['blocked'] === 0,
                ])>{{ $report['blocked'] }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('hr::views.disburse.blocked') }}</div>
            </div>
            <div>
                <div @class([
                    'text-2xl font-bold',
                    'text-warning-600 dark:text-warning-400' => $report['warnings'] > 0,
                    'text-gray-950 dark:text-white' => $report['warnings'] === 0,
                ])>{{ $report['warnings'] }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('hr::views.disburse.with_warnings') }}</div>
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">
                    {{ \Classivo\Hr\Support\Currency::symbol() }}{{ number_format($report['total_amount'], 2) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('hr::views.disburse.file_total') }}</div>
            </div>
        </div>

        @if ($report['already_paid'] > 0)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {!! __('hr::views.disburse.already_paid', ['count' => (int) $report['already_paid']]) !!}
            </p>
        @endif

        <div class="mt-6">
            <x-filament::button
                wire:click="download"
                icon="heroicon-o-arrow-down-tray"
                :disabled="$report['payable'] === 0"
            >
                {{ __('hr::views.disburse.download', ['count' => $report['payable']]) }}
            </x-filament::button>
        </div>
    </div>

    {{-- The row-by-row report. --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('hr::views.disburse.staff') }}</h3>

        @if (count($report['rows']) === 0)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {{ __('hr::views.disburse.no_payroll') }}
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">{{ __('hr::views.disburse.col_staff') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('hr::views.disburse.col_account') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('hr::views.disburse.col_ifsc') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('hr::views.disburse.col_net') }}</th>
                            <th class="py-2 font-medium">{{ __('hr::views.disburse.col_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-2 pr-4">
                                    <div class="font-medium text-gray-950 dark:text-white">
                                        {{ $row['staff']?->full_name ?? $row['label'] }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['staff']?->employee_id }}
                                        @if ($row['staff']?->trashed())
                                            <span class="text-danger-600 dark:text-danger-400">{{ __('hr::views.disburse.archived') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                    {{ $row['staff']?->bank_account_no ?: '—' }}
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['staff']?->bank_name ?: '' }}</div>
                                </td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">
                                    {{ $row['staff']?->bank_ifsc ?: '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ \Classivo\Hr\Support\Currency::symbol() }}{{ number_format($row['amount'], 2) }}
                                </td>
                                <td class="py-2">
                                    @if ($row['problems'] === [])
                                        <span class="text-success-600 dark:text-success-400">{{ __('hr::views.disburse.ready_row') }}</span>
                                    @else
                                        <ul class="space-y-1">
                                            @foreach ($row['problems'] as $problem)
                                                <li @class([
                                                    'text-xs',
                                                    'text-danger-600 dark:text-danger-400' => $problem['level'] === 'blocking',
                                                    'text-warning-600 dark:text-warning-400' => $problem['level'] !== 'blocking',
                                                ])>
                                                    {{ $problem['level'] === 'blocking' ? __('hr::views.disburse.blocked_prefix') : __('hr::views.disburse.warning_prefix') }}
                                                    {{ $problem['message'] }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {!! __('hr::views.disburse.footer') !!}
    </p>
</x-filament-panels::page>
