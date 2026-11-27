@php $result = $this->result(); @endphp
<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <table class="w-full text-left text-sm">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                <tr>
                    @foreach ($result['columns'] as $key => $label)
                        <th @class(['py-2', 'text-right' => in_array($key, $result['money'], true)])>{{ __($label) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($result['rows'] as $row)
                    <tr>
                        @foreach ($result['columns'] as $key => $label)
                            <td @class(['py-2', 'text-right' => in_array($key, $result['money'], true)])>{{ $this->format($key, $row[$key] ?? null, $result['money']) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($result['columns']) }}" class="py-4 text-center text-gray-500">{{ __('No records for these filters.') }}</td></tr>
                @endforelse
            </tbody>
            @if (count($result['rows']) > 0 && $result['totals'] !== [])
                <tfoot class="font-semibold">
                    <tr>
                        @foreach ($result['columns'] as $key => $label)
                            <td @class(['py-2', 'text-right' => in_array($key, $result['money'], true)])>
                                @if ($loop->first){{ __('Total') }}@elseif (array_key_exists($key, $result['totals'])){{ \Classivo\Fees\Support\Money::format($result['totals'][$key]) }}@endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </x-filament::section>
</x-filament-panels::page>
