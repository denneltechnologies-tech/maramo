@php
    $money = fn ($v) => \Classivo\Fees\Support\Money::format($v);
    $student = $this->student();
    $summary = $this->summary();
    $students = $this->students();
@endphp
<x-filament-panels::page>
    @if ($students->count() > 1)
        <div class="flex flex-wrap gap-2">
            @foreach ($students as $child)
                <x-filament::button size="sm" :color="$child->id === $this->studentId ? 'primary' : 'gray'" wire:click="switchStudent({{ $child->id }})">
                    {{ $child->full_name }}
                </x-filament::button>
            @endforeach
        </div>
    @endif

    @if ($student)
        <x-filament::section>
            <x-slot name="heading">{{ $student->full_name }} ({{ $student->admission_no }})</x-slot>
            <dl class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                <div><dt class="text-gray-500">{{ __('Total fees') }}</dt><dd class="font-semibold">{{ $money($summary['total']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Paid') }}</dt><dd class="font-semibold">{{ $money($summary['paid']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Discount') }}</dt><dd class="font-semibold">{{ $money($summary['discount']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Balance') }}</dt><dd class="font-semibold">{{ $money($summary['balance']) }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('Fee lines') }}</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-2">{{ __('Fee head') }}</th>
                        <th class="py-2">{{ __('Due date') }}</th>
                        <th class="py-2 text-right">{{ __('Amount') }}</th>
                        <th class="py-2 text-right">{{ __('Paid') }}</th>
                        <th class="py-2 text-right">{{ __('Balance') }}</th>
                        <th class="py-2">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->allocations() as $line)
                        <tr>
                            <td class="py-2">{{ $line->feeMaster?->feeType?->name ?? __('Fee') }}</td>
                            <td class="py-2">{{ $line->due_date?->format('d M Y') ?? '—' }}</td>
                            <td class="py-2 text-right">{{ $money($line->payable()) }}</td>
                            <td class="py-2 text-right">{{ $money($line->paid_amount) }}</td>
                            <td class="py-2 text-right font-semibold">{{ $money($line->balance()) }}</td>
                            <td class="py-2">{{ __(\Classivo\Fees\Models\FeeStudentAllocation::STATUSES[$line->status] ?? $line->status) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500">{{ __('No fees assigned yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('Receipts') }}</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500">
                    <tr>
                        <th class="py-2">{{ __('Receipt no') }}</th>
                        <th class="py-2">{{ __('Date') }}</th>
                        <th class="py-2">{{ __('Mode') }}</th>
                        <th class="py-2 text-right">{{ __('Paid') }}</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($this->receipts() as $receipt)
                        <tr>
                            <td class="py-2">{{ $receipt->receipt_no }}</td>
                            <td class="py-2">{{ $receipt->payment_date?->format('d M Y') }}</td>
                            <td class="py-2">{{ __(\Classivo\Fees\Models\FeePayment::MODES[$receipt->mode] ?? $receipt->mode) }}</td>
                            <td class="py-2 text-right">{{ $money($receipt->paid_amount) }}</td>
                            <td class="py-2 text-right">
                                @if ($receipt->isReverted())
                                    {{ __('Reverted') }}
                                @else
                                    <x-filament::link :href="route('fees.receipt.pdf', ['payment' => $receipt->id])" target="_blank">{{ __('Download') }}</x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500">{{ __('No receipts yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
