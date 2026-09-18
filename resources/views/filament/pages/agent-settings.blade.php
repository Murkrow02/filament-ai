<x-filament-panels::page>
    @include('filament-ai::partials.styles')

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    @unless ($hasResources)
        <x-filament::section icon="heroicon-o-information-circle">
            <x-slot name="heading">{{ __('filament-ai::messages.assistant_settings.no_resources') }}</x-slot>

            <p style="font-size:.875rem;color:var(--fai-muted);">
                {!! __('filament-ai::messages.assistant_settings.no_resources_body') !!}
            </p>
        </x-filament::section>
    @endunless

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('filament-ai::messages.assistant_settings.limits') }}</x-slot>

        <p style="font-size:.875rem;color:var(--fai-muted);">
            {{ __('filament-ai::messages.assistant_settings.limits_body') }}
        </p>
    </x-filament::section>
</x-filament-panels::page>
