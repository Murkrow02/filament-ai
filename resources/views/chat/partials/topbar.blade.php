<header class="fai-topbar">
    <button type="button" class="fai-icon-btn" id="fai-sidebar-toggle"
            title="{{ __('filament-ai::messages.chat.toggle_sidebar') }}"
            aria-label="{{ __('filament-ai::messages.chat.toggle_sidebar') }}">
        @include('filament-ai::chat.partials.icon', ['name' => 'menu'])
    </button>

    <span class="fai-topbar__title" id="fai-title">{{ $payload['current']['title'] ?? __('filament-ai::messages.chat.new_chat') }}</span>

    @if ($payload['context']['label'])
        {{-- What the user was looking at when they opened the chat, so "this
             order" means something. --}}
        <span class="fai-context" title="{{ $payload['context']['label'] }}">
            @include('filament-ai::chat.partials.icon', ['name' => 'book'])
            {{ $payload['context']['label'] }}
        </span>
    @endif

    @if ($showSettings)
        <button type="button" class="fai-icon-btn" id="fai-settings-open"
                title="{{ __('filament-ai::messages.chat.settings') }}"
                aria-label="{{ __('filament-ai::messages.chat.settings') }}">
            @include('filament-ai::chat.partials.icon', ['name' => 'gear'])
        </button>
    @endif
</header>
