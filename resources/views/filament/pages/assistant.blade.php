<x-filament-panels::page>
    @if (! $installed)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <p>{{ __('rag::rag.assistant.not_installed') }}</p>
        </x-filament::section>
    @else
        {{-- The same component the standalone page renders. `embedded` swaps
             its custom properties for Filament's tokens and hands the theme
             over to the panel; everything else is identical, on purpose. --}}
        <x-rag::chat :payload="$payload" :abilities="$abilities" embedded />
    @endif
</x-filament-panels::page>
