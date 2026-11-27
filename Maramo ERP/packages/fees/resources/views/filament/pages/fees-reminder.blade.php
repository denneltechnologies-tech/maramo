@php $money = fn ($v) => \Classivo\Fees\Support\Money::format($v); $rows = $this->overdueRows(); @endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('Overdue students') }}</x-slot>
        <x-slot name="description">{{ __('Reminders are sent by e-mail to the guardians on file, at 3, 7, 15 and 30 days overdue.') }}</x-slot>

        <table class="w-full text-left text-sm">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-2">{{ __('Admission no') }}</th>
                    <th class="py-2">{{ __('Name') }}</th>
                    <th class="py-2">{{ __('Class') }}</th>
                    <th class="py-2 text-right">{{ __('Days overdue') }}</th>
                    <th class="py-2">{{ __('Stage') }}</th>
                    <th class="py-2 text-right">{{ __('Overdue balance') }}</th>
                    <th class="py-2 text-right">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr>
                        <td class="py-2">{{ $row->admission_no }}</td>
                        <td class="py-2 font-medium">{{ $row->name }}</td>
                        <td class="py-2">{{ $row->class_section }}</td>
                        <td class="py-2 text-right">{{ $row->days_overdue }}</td>
                        <td class="py-2">{{ $this->rungLabel($row) }}</td>
                        <td class="py-2 text-right font-semibold">{{ $money($row->due) }}</td>
                        <td class="py-2 text-right">
                            @if ($this->canSendReminders())
                                <x-filament::button size="sm" color="gray" wire:click="sendReminder({{ $row->student_id }})">
                                    {{ __('Send reminder') }}
                                </x-filament::button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-4 text-center text-gray-500">{{ __('No overdue fees.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
