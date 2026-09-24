<x-filament-panels::page>
    @include('filament-ai::partials.styles')

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('filament-ai::messages.settings_page.not_editable') }}</x-slot>

        <p style="font-size:.875rem;color:var(--fai-muted);">{!! __('filament-ai::messages.settings_page.not_editable_body') !!}</p>
    </x-filament::section>
</x-filament-panels::page>
