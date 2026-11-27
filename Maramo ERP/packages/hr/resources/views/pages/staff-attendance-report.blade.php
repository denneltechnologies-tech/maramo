<x-filament-panels::page>
    {{ $this->form }}

    @php
        $rows = $this->rows();
        $labels = $this->statusLabels();
    @endphp

    <div class="fi-section overflow-x-auto rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('hr::views.report.heading') }}</h2>

        @if ($rows->isEmpty())
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('hr::views.report.empty') }}</p>
        @else
            <table class="mt-4 w-full text-left text-xs">
                <thead class="border-b border-gray-200 uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-2">{{ __('hr::views.report.staff_id') }}</th>
                        <th class="py-2 pr-2">{{ __('hr::views.report.name') }}</th>
                        @foreach ($labels as $key => $label)
                            <th class="py-2 pr-2 text-center">{{ $label }}</th>
                        @endforeach
                        <th class="py-2 pr-2 text-center">%</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-2 pr-2 font-medium">{{ $row->employee_id }}</td>
                            <td class="py-2 pr-2">{{ $row->name }}</td>
                            @foreach ($labels as $key => $label)
                                <td class="py-2 pr-2 text-center">{{ $row->counts[$key] ?? 0 }}</td>
                            @endforeach
                            <td class="py-2 pr-2 text-center font-semibold">{{ $row->percentage }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
