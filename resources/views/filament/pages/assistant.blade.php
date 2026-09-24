<x-filament-panels::page full-height="true">

    @if (! $installed)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <p>{{ __('filament-ai::messages.assistant.unavailable') }}</p>
            @if (\Murkrow\FilamentAi\Chat\ChatAbilities::allows('debug'))
                <p style="margin-top:.5rem;font-size:.875rem;opacity:.8;">{{ __('filament-ai::messages.assistant.not_installed') }}</p>
            @endif
        </x-filament::section>
    @else
        {{-- The same component the standalone page renders. `embedded` swaps
             its custom properties for Filament's tokens and hands the theme
             over to the panel; everything else is identical, on purpose. --}}
        <x-filament-ai::chat :payload="$payload" :abilities="$abilities" embedded />
    @endif
</x-filament-panels::page>
