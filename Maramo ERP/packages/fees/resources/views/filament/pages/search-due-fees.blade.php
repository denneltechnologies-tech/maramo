@php $money = fn ($v) => \Classivo\Fees\Support\Money::format($v); @endphp
<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">{{ __('Students with outstanding fees') }}</x-slot>
        <x-slot name="description">{{ __('Grand total due') }}: {{ $money($this->grandTotalDue()) }}</x-slot>

        <table class="w-full text-left text-sm">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-2">{{ __('Admission no') }}</th>
                    <th class="py-2">{{ __('Name') }}</th>
                    <th class="py-2">{{ __('Class') }}</th>
                    <th class="py-2">{{ __('Guardian') }}</th>
                    <th class="py-2">{{ __('Phone') }}</th>
                    <th class="py-2 text-right">{{ __('Overdue') }}</th>
                    <th class="py-2 text-right">{{ __('Balance') }}</th>
                    <th class="py-2 text-right">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($this->dueRows() as $row)
                    <tr>
                        <td class="py-2">{{ $row->admission_no }}</td>
                        <td class="py-2 font-medium">{{ $row->name }}</td>
                        <td class="py-2">{{ $row->class }}</td>
                        <td class="py-2">{{ $row->guardian }}</td>
                        <td class="py-2">{{ $row->phone }}</td>
                        <td class="py-2 text-right">{{ $money($row->overdue) }}</td>
                        <td class="py-2 text-right font-semibold">{{ $money($row->due) }}</td>
                        <td class="py-2 text-right">
                            @if (\Classivo\Fees\Filament\Pages\CollectFees::canAccess())
                                <x-filament::link :href="\Classivo\Fees\Filament\Pages\CollectFees::getUrl(['student' => $row->student_id])">{{ __('Collect') }}</x-filament::link>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-4 text-center text-gray-500">{{ __('No outstanding fees.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
