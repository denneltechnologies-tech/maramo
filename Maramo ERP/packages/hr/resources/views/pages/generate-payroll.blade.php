<x-filament-panels::page>
    {{ $this->form }}

    <div>
        <x-filament::button wire:click="generate" icon="heroicon-o-cog-6-tooth">
            {{ __('hr::views.generate.button') }}
        </x-filament::button>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {!! __('hr::views.generate.p1') !!}
    </p>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {!! __('hr::views.generate.p2') !!}
    </p>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {!! __('hr::views.generate.p3') !!}
    </p>
</x-filament-panels::page>
