<x-filament-panels::page>
    <form wire:submit="markAbsent" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-user-minus">
            {{ __('classivo-timetable::timetable.substitution.mark_absent') }}
        </x-filament::button>
    </form>

    @php($rows = $this->rows())
    <x-filament::section :heading="__('classivo-timetable::timetable.substitution.periods_today')">
        @if ($rows === [])
            <p class="text-sm text-gray-500">{{ __('classivo-timetable::timetable.substitution.none') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start">
                            <th class="p-2 text-start">{{ __('classivo-timetable::timetable.substitution.teacher') }}</th>
                            <th class="p-2 text-start">{{ __('classivo-timetable::timetable.form.period') }}</th>
                            <th class="p-2 text-start">{{ __('classivo-timetable::timetable.form.class') }}</th>
                            <th class="p-2 text-start">{{ __('classivo-timetable::timetable.form.subject') }}</th>
                            <th class="p-2 text-start">{{ __('classivo-timetable::timetable.substitution.substitute') }}</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10" wire:key="sub-{{ $row['id'] }}">
                                <td class="p-2">{{ $row['absent'] }}</td>
                                <td class="p-2 whitespace-nowrap">{{ $row['period_no'] }} · {{ $row['time'] }}</td>
                                <td class="p-2">{{ $row['class'] }}</td>
                                <td class="p-2">{{ $row['subject'] }}</td>
                                <td class="p-2">
                                    @if ($row['status'] === 'assigned')
                                        <span class="font-medium">{{ $row['substitute'] }}</span>
                                    @elseif ($row['candidates'] === [])
                                        <span class="text-danger-600">{{ __('classivo-timetable::timetable.substitution.no_free_teacher') }}</span>
                                    @else
                                        <select wire:model="choices.{{ $row['id'] }}" class="fi-select-input rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                                            <option value="">{{ __('classivo-timetable::timetable.substitution.choose_teacher') }}</option>
                                            @foreach ($row['candidates'] as $c)
                                                <option value="{{ $c['id'] }}">
                                                    {{ $c['subject_match'] ? '★ ' : '' }}{{ $c['name'] }}
                                                    — {{ __('classivo-timetable::timetable.substitution.load', ['count' => $c['load']]) }}{{ $c['knows_class'] ? ' · '.__('classivo-timetable::timetable.substitution.knows_class') : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>
                                <td class="p-2 whitespace-nowrap text-end">
                                    @if ($row['status'] === 'pending' && $row['candidates'] !== [])
                                        <x-filament::button size="sm" wire:click="assign({{ $row['id'] }})">{{ __('classivo-timetable::timetable.substitution.assign') }}</x-filament::button>
                                    @endif
                                    <x-filament::button size="sm" color="gray" wire:click="cancel({{ $row['id'] }})">{{ __('classivo-timetable::timetable.substitution.cancel') }}</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
