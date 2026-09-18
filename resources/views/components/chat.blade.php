{{--
    The assistant chat, as one component.

    There is exactly one chat in this package: the standalone page at
    /ai/chat and the panel page render this same markup, the same stylesheet
    and the same script. It is built out of plain HTML and CSS custom
    properties rather than Filament components on purpose -- that is what lets
    it run on a page with no panel around it -- and `embedded` rewrites those
    properties from Filament's own tokens so it does not look like a guest
    inside one.

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

    // The panel is the settings ability alone: what is inside it is gated
    // field by field, and a panel with nothing in it is a button that opens an
    // empty box.
    $showSettings = $abilities['settings'] && $abilities['model'] && $payload['models'] !== [];

    // Rendered server-side so reopening a saved chat does not flash the empty
    // state before the script has run.
    $hasMessages = ($payload['current']['messages'] ?? []) !== [];
@endphp

@if ($assets)
    <link rel="stylesheet" href="{{ AssetController::url('filament-ai-chat.css') }}">
@endif

<div id="fai-chat" class="rag" data-sidebar="{{ $embedded ? 'closed' : 'open' }}" @if ($embedded) data-fai-embedded @endif>
    @include('filament-ai::chat.partials.sidebar')

    <main class="fai-main">
        @include('filament-ai::chat.partials.topbar')

        <div class="fai-scroll" id="fai-scroll">
            @include('filament-ai::chat.partials.empty-state')

            <div class="fai-thread-body" id="fai-stream" @if (! $hasMessages) hidden @endif></div>
        </div>

        @include('filament-ai::chat.partials.composer')
    </main>

    @if ($showSettings)
        <div class="fai-backdrop" id="fai-backdrop" hidden></div>
        @include('filament-ai::chat.partials.settings')
    @endif
</div>

{{-- The whole authorization picture, resolved server-side. A value this user
     may not see is absent here, not merely hidden by CSS. --}}
<script type="application/json" id="fai-chat-payload">@json($payload + ['strings' => __('filament-ai::messages.chat.js'), 'embedded' => (bool) $embedded])</script>

@if ($assets)
    <script src="{{ AssetController::url('filament-ai-chat.js') }}" defer></script>
@endif
