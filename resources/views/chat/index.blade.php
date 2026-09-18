@php
    use Murkrow\FilamentAi\Http\Controllers\AssetController;

    /** @var array<string, mixed> $payload */
    /** @var array<string, bool> $abilities */
@endphp

@extends($layout)

@section('fai-title', __('filament-ai::messages.chat.title'))

@section('fai-head')
    <link rel="stylesheet" href="{{ AssetController::url('filament-ai-chat.css') }}">

    {{-- The accent is the one thing a host is likely to want to change, so it
         is a variable rather than a rebuild. --}}
    <style>:root { --fai-accent: {{ $payload['brand']['accent'] }}; }</style>

    {{-- Applied before first paint. Reading the stored preference afterwards
         would flash the light theme at somebody who chose dark. --}}
    <script>
        (function () {
            var theme = 'light';
            try {
                var stored = localStorage.getItem('filament-ai-chat-theme');
                theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            } catch (error) { /* storage unavailable */ }
            document.documentElement.dataset.faiTheme = theme;
        })();
    </script>
@endsection

@section('fai-content')
    {{-- The stylesheet is already in the head above, so the component does not
         emit it again: a stylesheet loaded from the body flashes. --}}
    <x-filament-ai::chat :payload="$payload" :abilities="$abilities" :assets="false" />
@endsection

@section('fai-scripts')
    <script src="{{ AssetController::url('filament-ai-chat.js') }}" defer></script>
@endsection
