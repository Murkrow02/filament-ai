@php
    use Murkrow\FilamentAi\Http\Controllers\AssetController;

    /** @var array<string, mixed> $payload */
    /** @var array<string, bool> $abilities */

    $showSettings = $abilities['advanced'] || $abilities['model'] || $abilities['sources'];

    // Rendered server-side so reopening a saved chat does not flash the empty
    // state before the script has run.
    $hasMessages = ($payload['current']['messages'] ?? []) !== [];
@endphp

@extends($layout)

@section('rag-title', __('rag::rag.chat.title'))

@section('rag-head')
    <link rel="stylesheet" href="{{ AssetController::url('rag-chat.css') }}">

    {{-- The accent is the one thing a host is likely to want to change, so it
         is a variable rather than a rebuild. --}}
    <style>:root { --rag-accent: {{ $payload['brand']['accent'] }}; }</style>

    {{-- Applied before first paint. Reading the stored preference afterwards
         would flash the light theme at somebody who chose dark. --}}
    <script>
        (function () {
            var theme = 'light';
            try {
                var stored = localStorage.getItem('rag-chat-theme');
                theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            } catch (error) { /* storage unavailable */ }
            document.documentElement.dataset.ragTheme = theme;
        })();
    </script>
@endsection

@section('rag-content')
    <div id="rag-chat" class="rag" data-sidebar="open">
        @include('rag::chat.partials.sidebar')

        <main class="rag-main">
            @include('rag::chat.partials.topbar')

            <div class="rag-scroll" id="rag-scroll">
                @include('rag::chat.partials.empty-state')

                <div class="rag-thread-body" id="rag-stream" @if (! $hasMessages) hidden @endif></div>
            </div>

            @include('rag::chat.partials.composer')
        </main>

        @include('rag::chat.partials.drawer')

        @if ($showSettings)
            <div class="rag-backdrop" id="rag-backdrop" hidden></div>
            @include('rag::chat.partials.settings')
        @endif
    </div>
@endsection

@section('rag-scripts')
    {{-- The whole authorization picture, resolved server-side. A value this
         user may not see is absent here, not merely hidden by CSS. --}}
    <script type="application/json" id="rag-chat-payload">@json($payload + ['strings' => __('rag::rag.chat.js')])</script>
    <script src="{{ AssetController::url('rag-chat.js') }}" defer></script>
@endsection
