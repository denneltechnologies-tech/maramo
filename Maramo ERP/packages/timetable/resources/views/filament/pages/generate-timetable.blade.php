<x-filament-panels::page>
    <form wire:submit="generatePreview" class="space-y-4">
        {{ $this->form }}

        <div class="flex gap-3">
            <x-filament::button type="submit" icon="heroicon-o-sparkles">
                {{ __('classivo-timetable::timetable.generate.preview') }}
            </x-filament::button>

            @if ($this->isSolvedPreview())
                <x-filament::button type="button" color="success" icon="heroicon-o-check" wire:click="saveTimetables">
                    {{ __('classivo-timetable::timetable.generate.save') }}
                </x-filament::button>
            @endif
        </div>
    </form>

    @if ($preview)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('classivo-timetable::timetable.generate.status.'.$preview['status']) }}
            </x-slot>
            <x-slot name="description">
                {{ __('classivo-timetable::timetable.generate.stats', [
                    'ms' => $preview['stats']['elapsed_ms'] ?? 0,
                    'repeats' => $preview['stats']['same_subject_repeats'] ?? 0,
                ]) }}
            </x-slot>

            @if ($preview['reasons'] || $preview['unplaced'])
                <ul class="list-disc ps-6 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($preview['reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                    @foreach ($preview['unplaced'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @foreach ($preview['classes'] as $class)
            <x-filament::section :heading="$class['name']" collapsible>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr>
                                <th class="p-1 text-start">{{ __('classivo-timetable::timetable.form.period') }}</th>
                                @foreach ($preview['days'] as $label)
                                    <th class="p-1 text-start">{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($class['rows'] as $row)
                                <tr @class(['bg-gray-50 dark:bg-white/5' => $row['break']])>
                                    <td class="p-1 whitespace-nowrap">{{ $row['no'] }} · {{ $row['time'] }}</td>
                                    @foreach ($row['cells'] as $cell)
                                        <td class="p-1 border-t border-gray-200 dark:border-white/10">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
