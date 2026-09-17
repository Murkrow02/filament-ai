{{--
    The chat, as one component.

    There is exactly one chat in this package: the standalone page at
    /rag/chat and the panel page render this same markup, the same stylesheet
    and the same script. It is built out of plain HTML and CSS custom
    properties rather than Filament components on purpose -- that is what lets
    it run on a page that has no panel around it -- and `embedded` rewrites
    those properties from Filament's own tokens so it does not look like a
    guest inside one.

    Props:
      payload   -- everything the browser is allowed to know, from ChatPayload
      abilities -- the resolved ability map, for what to render at all
      embedded  -- true inside a Filament page: panel chrome, panel theme
      assets    -- emit the stylesheet and script here (the standalone page
                   loads them from its own head instead, to avoid a flash)
--}}
@props([
    'payload',
    'abilities',
    'embedded' => false,
    'assets' => true,
])

@php
    use Murkrow\FilamentAi\Http\Controllers\AssetController;

    $showSettings = $abilities['advanced'] || $abilities['model'] || $abilities['sources'];

    // Rendered server-side so reopening a saved chat does not flash the empty
    // state before the script has run.
    $hasMessages = ($payload['current']['messages'] ?? []) !== [];
@endphp

@if ($assets)
    <link rel="stylesheet" href="{{ AssetController::url('rag-chat.css') }}">
@endif

<div id="rag-chat" class="rag" data-sidebar="{{ $embedded ? 'closed' : 'open' }}" @if ($embedded) data-rag-embedded @endif>
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

{{-- The whole authorization picture, resolved server-side. A value this user
     may not see is absent here, not merely hidden by CSS. --}}
<script type="application/json" id="rag-chat-payload">@json($payload + ['strings' => __('rag::rag.chat.js'), 'embedded' => (bool) $embedded])</script>

@if ($assets)
    <script src="{{ AssetController::url('rag-chat.js') }}" defer></script>
@endif
